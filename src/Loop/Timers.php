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
use SplPriorityQueue;
use InvalidArgumentException;

class Timers
{

    const MIN_RESOLUTION = 0.001;

    private $loop;

    private $time;

    private $active;

    private $timers;

    public function __construct(Event\Interfaces\Loop $loop)
    {
        $this->loop = $loop;
        $this->active = array();
        $this->timers = new SplPriorityQueue();
    }

    public function updateTime()
    {
        return $this->time = microtime(true);
    }

    public function getTime()
    {
        return $this->time ?  : $this->updateTime();
    }

    public function add($interval, $callback, $periodic = false)
    {
        if ($interval < self::MIN_RESOLUTION) {
            throw new InvalidArgumentException('Timer events do not support sub-millisecond timeouts.');
        }
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('The callback must be a callable object.');
        }
        $interval = (float) $interval;
        $timer = (object) array(
            'interval' => $interval,
            'callback' => $callback,
            'periodic' => $periodic,
            'scheduled' => $interval + $this->getTime()
        );
        $timer->signature = spl_object_hash($timer);
        $this->timers->insert($timer, -$timer->scheduled);
        $this->active[$timer->signature] = $timer;
        return $timer->signature;
    }

    public function cancel($signature)
    {
        unset($this->active[$signature]);
    }

    public function getFirst()
    {
        if ($this->timers->isEmpty()) {
            return null;
        }
        return $this->timers->top()->scheduled;
    }

    public function isEmpty()
    {
        return !$this->active;
    }

    public function tick()
    {
        $time = $this->updateTime();
        $timers = $this->timers;
        while (!$timers->isEmpty() && $timers->top()->scheduled < $time) {
            $timer = $timers->extract();
            if (isset($this->active[$timer->signature])) {
                call_user_func($timer->callback, $timer->signature, $this->loop);
                if ($timer->periodic === true) {
                    $timer->scheduled = $timer->interval + $time;
                    $timers->insert($timer, -$timer->scheduled);
                } else {
                    unset($this->active[$timer->signature]);
                }
            }
        }
    }
}
