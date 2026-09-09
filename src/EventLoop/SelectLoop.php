<?php

declare(strict_types=1);

namespace App\EventLoop;

/**
 * An EventLoop built on stream_select().
 */
final class SelectLoop implements EventLoop
{
    /** @var array<int, array{0: resource, 1: callable(resource): void}> */
    private array $readListeners = [];

    /** @var array<int, array{0: resource, 1: callable(resource): void}> */
    private array $writeListeners = [];

    private bool $running = false;

    public function onReadable(mixed $stream, callable $listener): void
    {
        $this->readListeners[get_resource_id($stream)] = [$stream, $listener];
    }

    public function onWritable(mixed $stream, callable $listener): void
    {
        $this->writeListeners[get_resource_id($stream)] = [$stream, $listener];
    }

    public function removeReadable(mixed $stream): void
    {
        unset($this->readListeners[get_resource_id($stream)]);
    }

    public function removeWritable(mixed $stream): void
    {
        unset($this->writeListeners[get_resource_id($stream)]);
    }

    public function run(): void
    {
        $this->running = true;

        while ($this->running) { // @phpstan-ignore while.alwaysTrue
            $this->tick(null);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Runs a single wait-and-dispatch pass. Returns the number of streams
     * that were ready, or 0 if the wait timed out.
     */
    public function tick(?float $timeoutSeconds): int
    {
        $read = array_map(static fn (array $entry) => $entry[0], $this->readListeners);
        $write = array_map(static fn (array $entry) => $entry[0], $this->writeListeners);

        if ($read === [] && $write === []) {
            return 0;
        }

        $except = null;
        $seconds = $timeoutSeconds === null ? null : (int) floor($timeoutSeconds);
        $microseconds = $timeoutSeconds === null ? null : (int) (($timeoutSeconds - $seconds) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready === false || $ready === 0) {
            return 0;
        }

        foreach ($read as $stream) {
            $id = get_resource_id($stream);

            if (isset($this->readListeners[$id])) {
                ($this->readListeners[$id][1])($stream);
            }
        }

        foreach ($write as $stream) {
            $id = get_resource_id($stream);

            if (isset($this->writeListeners[$id])) {
                ($this->writeListeners[$id][1])($stream);
            }
        }

        return $ready;
    }
}
