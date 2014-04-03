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
namespace Bread\Event\Interfaces;

use Bread\Event\Loop\Interfaces\Timer;

interface Loop
{

    public function addReadStream($stream, callable $listener);

    public function addWriteStream($stream, callable $listener);

    public function removeReadStream($stream);

    public function removeWriteStream($stream);

    public function removeStream($stream);

    public function addTimer($interval, callable $callback);

    public function addPeriodicTimer($interval, callable $callback);

    public function addCronjob($cronExpression, callable $callback);

    public function cancelTimer(Timer $timer);

    public function isTimerActive(Timer $timer);

    public function nextTick(callable $listener);

    public function futureTick(callable $listener);

    public function tick();

    public function run();

    public function stop();
}
