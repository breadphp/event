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
use libev\EventLoop;
use libev\IOEvent;
use libev\TimerEvent;
use Bread\Event\Loop\Tick\FutureTickQueue;
use Bread\Event\Loop\Tick\NextTickQueue;
use Braed\Event\Loop\Timer;
use Bread\Event\Loop\Interfaces\Timer as TimerInterface;
use Bread\Event\Interfaces\Loop;
use SplObjectStorage;

/**
 * @see https://github.com/m4rw3r/php-libev
 * @see https://gist.github.com/1688204
 */
class LibEv implements Loop
{
    private $loop;
    private $nextTickQueue;
    private $futureTickQueue;
    private $timerEvents;
    private $readEvents = [];
    private $writeEvents = [];
    private $running;

    public function __construct()
    {
        $this->loop = new EventLoop();
        $this->nextTickQueue = new NextTickQueue($this);
        $this->futureTickQueue = new FutureTickQueue($this);
        $this->timerEvents = new SplObjectStorage();
    }

    public function addReadStream($stream, callable $listener)
    {
        $callback = function () use ($stream, $listener) {
            call_user_func($listener, $stream, $this);
        };

        $event = new IOEvent($callback, $stream, IOEvent::READ);
        $this->loop->add($event);

        $this->readEvents[(int) $stream] = $event;
    }

    public function addWriteStream($stream, callable $listener)
    {
        $callback = function () use ($stream, $listener) {
            call_user_func($listener, $stream, $this);
        };

        $event = new IOEvent($callback, $stream, IOEvent::WRITE);
        $this->loop->add($event);

        $this->writeEvents[(int) $stream] = $event;
    }

    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->readEvents[$key])) {
            $this->readEvents[$key]->stop();
            unset($this->readEvents[$key]);
        }
    }

    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->writeEvents[$key])) {
            $this->writeEvents[$key]->stop();
            unset($this->writeEvents[$key]);
        }
    }

    public function removeStream($stream)
    {
        $this->removeReadStream($stream);
        $this->removeWriteStream($stream);
    }

    public function addTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, false);

        $callback = function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);

            if ($this->isTimerActive($timer)) {
                $this->cancelTimer($timer);
            }
        };

        $event = new TimerEvent($callback, $timer->getInterval());
        $this->timerEvents->attach($timer, $event);
        $this->loop->add($event);

        return $timer;
    }

    public function addPeriodicTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, true);

        $callback = function () use ($timer) {
            call_user_func($timer->getCallback(), $timer);
        };

        $event = new TimerEvent($callback, $interval, $interval);
        $this->timerEvents->attach($timer, $event);
        $this->loop->add($event);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer)
    {
        if (isset($this->timerEvents[$timer])) {
            $this->loop->remove($this->timerEvents[$timer]);
            $this->timerEvents->detach($timer);
        }
    }

    public function isTimerActive(TimerInterface $timer)
    {
        return $this->timerEvents->contains($timer);
    }

    public function nextTick(callable $listener)
    {
        $this->nextTickQueue->add($listener);
    }

    public function futureTick(callable $listener)
    {
        $this->futureTickQueue->add($listener);
    }

    public function tick()
    {
        $this->nextTickQueue->tick();

        $this->futureTickQueue->tick();

        $this->loop->run(EventLoop::RUN_ONCE | EventLoop::RUN_NOWAIT);
    }

    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->nextTickQueue->tick();

            $this->futureTickQueue->tick();

            $flags = EventLoop::RUN_ONCE;
            if (!$this->running || !$this->nextTickQueue->isEmpty() || !$this->futureTickQueue->isEmpty()) {
                $flags |= EventLoop::RUN_NOWAIT;
            } elseif (!$this->readEvents && !$this->writeEvents && !$this->timerEvents->count()) {
                break;
            }

            $this->loop->run($flags);
        }
    }

    public function stop()
    {
        $this->running = false;
    }
}
