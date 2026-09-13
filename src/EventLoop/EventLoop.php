<?php

declare(strict_types=1);

namespace PhpMiniCache\EventLoop;

/**
 * Waits for readable/writable streams and dispatches them to listeners,
 * instead of blocking on one client at a time.
 */
interface EventLoop
{
    /**
     * @param resource $stream
     * @param callable(resource): void $listener
     */
    public function onReadable(mixed $stream, callable $listener): void;

    /**
     * @param resource $stream
     * @param callable(resource): void $listener
     */
    public function onWritable(mixed $stream, callable $listener): void;

    /** @param resource $stream */
    public function removeReadable(mixed $stream): void;

    /** @param resource $stream */
    public function removeWritable(mixed $stream): void;

    /**
     * Schedules $callback to run every $intervalSeconds.
     *
     * @param callable(): void $callback
     */
    public function every(float $intervalSeconds, callable $callback): Timer;

    /**
     * Schedules $callback to run once, after $delaySeconds.
     *
     * @param callable(): void $callback
     */
    public function after(float $delaySeconds, callable $callback): Timer;

    /**
     * Runs until stop() is called.
     */
    public function run(): void;

    public function stop(): void;

    /**
     * Observability counters for this loop's own behavior (iterations,
     * busy/idle time, max lag) - see EventLoopMetrics.
     */
    public function metrics(): EventLoopMetrics;
}
