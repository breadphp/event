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
use libev;

class LibEv implements Event\Interfaces\Loop {
  private $loop;
  private $readEvents = array();
  private $writeEvents = array();
  private $timers = array();

  public function __construct() {
    $this->loop = new libev\EventLoop();
  }

  public function addReadStream($stream, $listener) {
    $this->addStream($stream, $listener, libev\IOEvent::READ);
  }

  public function addWriteStream($stream, $listener) {
    $this->addStream($stream, $listener, libev\IOEvent::WRITE);
  }

  public function removeReadStream($stream) {
    $this->readEvents[(int) $stream]->stop();
    unset($this->readEvents[(int) $stream]);
  }

  public function removeWriteStream($stream) {
    $this->writeEvents[(int) $stream]->stop();
    unset($this->writeEvents[(int) $stream]);
  }

  public function removeStream($stream) {
    if (isset($this->readEvents[(int) $stream])) {
      $this->removeReadStream($stream);
    }
    if (isset($this->writeEvents[(int) $stream])) {
      $this->removeWriteStream($stream);
    }
  }

  private function addStream($stream, $listener, $flags) {
    $listener = $this->wrapStreamListener($stream, $listener, $flags);
    $event = new libev\IOEvent($listener, $stream, $flags);
    $this->loop->add($event);
    if (($flags & libev\IOEvent::READ) === $flags) {
      $this->readEvents[(int) $stream] = $event;
    }
    elseif (($flags & libev\IOEvent::WRITE) === $flags) {
      $this->writeEvents[(int) $stream] = $event;
    }
  }

  private function wrapStreamListener($stream, $listener, $flags) {
    if (($flags & libev\IOEvent::READ) === $flags) {
      $removeCallback = array($this, 'removeReadStream');
    }
    elseif (($flags & libev\IOEvent::WRITE) === $flags) {
      $removeCallback = array($this, 'removeWriteStream');
    }

    return function ($event) use ($stream, $listener, $removeCallback) {
      if (feof($stream)) {
        call_user_func($removeCallback, $stream);
        return;
      }
      call_user_func($listener, $stream);
    };
  }

  public function addTimer($interval, $callback) {
    $dummyCallback = function () {
    };
    $timer = new libev\TimerEvent($dummyCallback, $interval);
    return $this->createTimer($timer, $callback, false);
  }

  public function addPeriodicTimer($interval, $callback) {
    $dummyCallback = function () {
    };
    $timer = new libev\TimerEvent($dummyCallback, $interval, $interval);
    return $this->createTimer($timer, $callback, true);
  }

  public function cancelTimer($signature) {
    $this->loop->remove($this->timers[$signature]);
    unset($this->timers[$signature]);
  }

  private function createTimer($timer, $callback, $periodic) {
    $signature = spl_object_hash($timer);
    $callback = $this->wrapTimerCallback($signature, $callback, $periodic);
    $timer->setCallback($callback);
    $this->timers[$signature] = $timer;
    $this->loop->add($timer);
    return $signature;
  }

  private function wrapTimerCallback($signature, $callback, $periodic) {
    return function ($event) use ($signature, $callback, $periodic) {
      call_user_func($callback, $signature, $this);
      if (!$periodic) {
        $this->cancelTimer($signature);
      }
    };
  }

  public function tick() {
    $this->loop->run(libev\EventLoop::RUN_ONCE);
  }

  public function run() {
    $this->loop->run();
  }

  public function stop() {
    $this->loop->breakLoop();
  }
}
