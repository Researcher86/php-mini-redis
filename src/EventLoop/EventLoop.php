<?php

declare(strict_types=1);

namespace App\EventLoop;

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
     * Runs until stop() is called.
     */
    public function run(): void;

    public function stop(): void;
}
