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

use Bread\Event\Loop\Tick\FutureTickQueue;
use Bread\Event\Loop\Tick\NextTickQueue;
use Bread\Event\Loop\Timer;
use Bread\Event\Loop\Interfaces\Timer as TimerInterface;
use Bread\Event\Loop\Timers;
use Bread\Event\Interfaces\Loop;
use Cron\CronExpression;

class StreamSelect implements Loop
{
    const MICROSECONDS_PER_SECOND = 1000000;

    private $nextTickQueue;
    private $futureTickQueue;
    private $timers;
    private $readStreams = [];
    private $readListeners = [];
    private $writeStreams = [];
    private $writeListeners = [];
    private $running;

    public function __construct()
    {
        $this->nextTickQueue = new NextTickQueue($this);
        $this->futureTickQueue = new FutureTickQueue($this);
        $this->timers = new Timers();
    }

    public function addReadStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->readStreams[$key])) {
            $this->readStreams[$key] = $stream;
            $this->readListeners[$key] = $listener;
        }
    }

    public function addWriteStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->writeStreams[$key])) {
            $this->writeStreams[$key] = $stream;
            $this->writeListeners[$key] = $listener;
        }
    }

    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->readStreams[$key],
            $this->readListeners[$key]
        );
    }

    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->writeStreams[$key],
            $this->writeListeners[$key]
        );
    }

    public function removeStream($stream)
    {
        $this->removeReadStream($stream);
        $this->removeWriteStream($stream);
    }

    public function addTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, false);

        $this->timers->add($timer);

        return $timer;
    }

    public function addPeriodicTimer($interval, callable $callback)
    {
        $timer = new Timer($this, $interval, $callback, true);

        $this->timers->add($timer);

        return $timer;
    }

    public function addCronjob($cronExpression, callable $callback)
    {
        $cron = CronExpression::factory($cronExpression);
        $time = (int) $cron->getNextRunDate()->format('U');
        $interval = $time - time();
        return $this->addPeriodicTimer($interval, function ($timer) use ($cron, $callback) {
            $time = (int) $cron->getNextRunDate()->format('U');
            $interval = $time - time();
            $timer->setInterval($interval);
            return call_user_func($callback, $timer);
        });
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->timers->cancel($timer);
    }

    public function isTimerActive(TimerInterface $timer)
    {
        return $this->timers->contains($timer);
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

        $this->timers->tick();

        $this->waitForStreamActivity(0);
    }

    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->nextTickQueue->tick();

            $this->futureTickQueue->tick();

            $this->timers->tick();

            // Next-tick or future-tick queues have pending callbacks ...
            if (!$this->running || !$this->nextTickQueue->isEmpty() || !$this->futureTickQueue->isEmpty()) {
                $timeout = 0;

            // There is a pending timer, only block until it is due ...
            } elseif ($scheduledAt = $this->timers->getFirst()) {
                if (0 > $timeout = $scheduledAt - $this->timers->getTime()) {
                    $timeout = 0;
                }

            // The only possible event is stream activity, so wait forever ...
            } elseif ($this->readStreams || $this->writeStreams) {
                $timeout = null;

            // There's nothing left to do ...
            } else {
                break;
            }

            $this->waitForStreamActivity($timeout * self::MICROSECONDS_PER_SECOND);
        }
    }

    public function stop()
    {
        $this->running = false;
    }

    /**
     * Wait/check for stream activity, or until the next timer is due.
     */
    private function waitForStreamActivity($timeout)
    {
        $read  = $this->readStreams;
        $write = $this->writeStreams;

        $this->streamSelect($read, $write, $timeout);

        foreach ($read as $stream) {
            $key = (int) $stream;

            if (isset($this->readListeners[$key])) {
                call_user_func($this->readListeners[$key], $stream, $this);
            }
        }

        foreach ($write as $stream) {
            $key = (int) $stream;

            if (isset($this->writeListeners[$key])) {
                call_user_func($this->writeListeners[$key], $stream, $this);
            }
        }
    }

    /**
     * Emulate a stream_select() implementation that does not break when passed
     * empty stream arrays.
     *
     * @param array        &$read   An array of read streams to select upon.
     * @param array        &$write  An array of write streams to select upon.
     * @param integer|null $timeout Activity timeout in microseconds, or null to wait forever.
     *
     * @return integer The total number of streams that are ready for read/write.
     */
    protected function streamSelect(array &$read, array &$write, $timeout)
    {
        if ($read || $write) {
            $except = null;

            return @stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout);
        }

        usleep($timeout);

        return 0;
    }
}

