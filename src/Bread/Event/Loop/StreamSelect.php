<?php
/**
 * Bread PHP Framework (http://github.com/saiv/Bread)
 * Copyright 2010-2012, SAIV Development Team <development@saiv.it>
 *
 * Licensed under a Creative Commons Attribution 3.0 Unported License.
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright  Copyright 2010-2012, SAIV Development Team <development@saiv.it>
 * @link       http://github.com/saiv/Bread Bread PHP Framework
 * @package    Bread
 * @since      Bread PHP Framework
 * @license    http://creativecommons.org/licenses/by/3.0/
 */

namespace Bread\Event\Loop;

use Bread\Event;

class StreamSelect implements Event\Interfaces\Loop {
  const QUANTUM_INTERVAL = 1000000;

  protected $timers;
  protected $running = false;
  protected $readStreams = array();
  protected $readListeners = array();
  protected $writeStreams = array();
  protected $writeListeners = array();

  public function __construct() {
    $this->timers = new Timers($this);
  }

  public function addReadStream($stream, $listener) {
    $id = (int) $stream;
    if (!isset($this->readStreams[$id])) {
      $this->readStreams[$id] = $stream;
      $this->readListeners[$id] = $listener;
    }
  }

  public function addWriteStream($stream, $listener) {
    $id = (int) $stream;
    if (!isset($this->writeStreams[$id])) {
      $this->writeStreams[$id] = $stream;
      $this->writeListeners[$id] = $listener;
    }
  }

  public function removeReadStream($stream) {
    $id = (int) $stream;
    unset($this->readStreams[$id], $this->readListeners[$id]);
  }

  public function removeWriteStream($stream) {
    $id = (int) $stream;
    unset($this->writeStreams[$id], $this->writeListeners[$id]);
  }

  public function removeStream($stream) {
    $this->removeReadStream($stream);
    $this->removeWriteStream($stream);
  }

  public function addTimer($interval, $callback) {
    return $this->timers->add($interval, $callback);
  }

  public function addPeriodicTimer($interval, $callback) {
    return $this->timers->add($interval, $callback, true);
  }

  public function cancelTimer($signature) {
    $this->timers->cancel($signature);
  }

  protected function getNextEventTimeInMicroSeconds() {
    $nextEvent = $this->timers->getFirst();
    if (null === $nextEvent) {
      return self::QUANTUM_INTERVAL;
    }
    $currentTime = microtime(true);
    if ($nextEvent > $currentTime) {
      return ($nextEvent - $currentTime) * 1000000;
    }
    return 0;
  }

  protected function sleepOnPendingTimers() {
    if ($this->timers->isEmpty()) {
      $this->running = false;
    }
    else {
      // We use usleep() instead of stream_select() to emulate timeouts
      // since the latter fails when there are no streams registered for
      // read / write events. Blame PHP for us needing this hack.
      usleep($this->getNextEventTimeInMicroSeconds());
    }
  }

  protected function runStreamSelect() {
    $read = $this->readStreams ? : null;
    $write = $this->writeStreams ? : null;
    $except = null;
    if (!$read && !$write) {
      $this->sleepOnPendingTimers();
      return;
    }

    if (stream_select($read, $write, $except, 0, $this->getNextEventTimeInMicroSeconds())
      > 0) {
      if ($read) {
        foreach ($read as $stream) {
          if (!isset($this->readListeners[(int) $stream])) {
            continue;
          }
          $listener = $this->readListeners[(int) $stream];
          if (call_user_func($listener, $stream, $this) === false) {
            $this->removeReadStream($stream);
          }
        }
      }
      if ($write) {
        foreach ($write as $stream) {
          if (!isset($this->writeListeners[(int) $stream])) {
            continue;
          }
          $listener = $this->writeListeners[(int) $stream];
          if (call_user_func($listener, $stream, $this) === false) {
            $this->removeWriteStream($stream);
          }
        }
      }
    }
  }

  public function tick() {
    $this->timers->tick();
    $this->runStreamSelect();
    return $this->running;
  }

  public function run() {
    $this->running = true;
    while ($this->tick()) {
      // NOOP
    }
  }

  public function stop() {
    $this->running = false;
  }
}
