<?php
namespace Carica\Firmata {

  use Carica\Io\ByteArray;
  use Carica\Io\Deferred;
  use Carica\Io\Event;
  use Carica\Io\Device;

  class I2C implements Device\I2C {

    use Event\Emitter\Aggregation;

    const REQUEST = 0x76;
    const REPLY = 0x77;
    const CONFIG = 0x78;

    const MODE_WRITE = 0;
    const MODE_READ = 1;
    const MODE_CONTINOUS_READ = 2;
    const MODE_STOP_READING = 3;

    /**
     * @var Board
     */
    private $_board = NULL;

    /**
     * @var bool Debug mode, blocks actual write() and issues a debug event.
     */
    private $_debug = FALSE;

    /**
     * @var bool
     */
    private $_isInitialized = FALSE;

    public function __construct(Board $board) {
      $this->_board = $board;
      $this->_board->events()->on(
        'response',
        function(Response $response) {
          if ($response->command == self::REPLY) {
            $reply = new I2C\Reply(self::REPLY, $response->getRawData());
            $this->events()->emit('reply-'.$reply->slaveAddress, $reply->data);
          }
        }
      );
    }

    /**
     * Enable/disable debug mode - Blocks write actions and emits a debug event with the data.
     * @param $enable
     */
    public function debug($enable) {
      $this->_debug = (bool)$enable;
    }

    /**
     * Emit the debug event with the address as hex string and data bytes in binary representation.
     * 
     * @param $method
     * @param $slaveAddress
     * @param $data
     */
    private function emitDebug($method, $slaveAddress, $data) {
      $this->emitEvent(
        'debug', 
        $method, 
        '0x'.str_pad(dechex($slaveAddress), 2, '0', STR_PAD_LEFT), 
        ByteArray::createFromArray($data)->asBitString()
      );
    }

     /**
     * Allow i2c read/write to make sure that config was called.
     */
    private function ensureConfiguration() {
      if (!$this->_isInitialized) {
        $this->configure();
        $this->_isInitialized = true;
      }
    }

    public function configure($delay = 0) {
      $this
        ->_board
        ->stream()
        ->write(
           array(
             Board::START_SYSEX,
             self::CONFIG,
             $delay >> 0xFF,
             ($delay >> 8) & 0xFF,
             Board::END_SYSEX
           )
        );
      $this->_isInitialized = true;
    }

    /**
     * Write some data to an i2c device
     *
     * @param integer $slaveAddress
     * @param string $data
     */
    public function write($slaveAddress, $data) {
      if ($this->_debug) {
        $this->emitDebug(__FUNCTION__, $slaveAddress, $data);
        return;
      }
      $this->ensureConfiguration();
      $request = new I2C\Request($this->_board, $slaveAddress, self::MODE_WRITE, $data);
      $request->send();
    }

    /**
     * Request data from an i2c device and trigger callback if the
     * data is sent.
     *
     * @param integer $slaveAddress
     * @param integer $byteCount
     * @return Deferred
     */
    public function read($slaveAddress, $byteCount) {
      $this->ensureConfiguration();
      $defer = new Deferred();
      $this->events()->once(
        'reply-'.$slaveAddress,
        function ($bytes) use ($defer, $byteCount) {
          if (count($bytes) == $byteCount) {
            $defer->resolve($bytes);
          } else {
            $defer->reject('Invalid I2C response.');
          }
        }
      );
      $request = new I2C\Request(
        $this->_board, $slaveAddress, self::MODE_READ, $byteCount
      );
      $request->send();
      return $defer;
    }

    /**
     * @param int $slaveAddress
     * @param int $byteCount
     * @param callable $listener
     */
    public function startReading($slaveAddress, $byteCount, callable $listener) {
      $this->ensureConfiguration();
      $this->stopReading($slaveAddress);
      $this->events()->on(
        'reply-'.$slaveAddress,
        function ($bytes) use ($slaveAddress, $listener, $byteCount) {
          if (count($bytes) == $byteCount) {
            $listener($bytes);
          } else {
            $this->stopReading($slaveAddress);
          }
        }
      );
      $request = new I2C\Request(
        $this->_board, $slaveAddress, self::MODE_CONTINOUS_READ, $byteCount
      );
      $request->send();
    }

    /**
     * @param int $slaveAddress
     */
    public function stopReading($slaveAddress) {
      $this->events()->removeAllListeners('reply-'.$slaveAddress);
      $request = new I2C\Request(
        $this->_board, $slaveAddress, self::MODE_STOP_READING
      );
      $request->send();
    }
  }
}