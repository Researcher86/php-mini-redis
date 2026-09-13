<?php

declare(strict_types=1);

namespace PhpMiniCache\EventLoop;

use PhpMiniCache\Support\Clock;
use PhpMiniCache\Support\SystemClock;

/**
 * An EventLoop built on stream_select(), extended with timers so time -
 * not just I/O - can wake it up.
 */
final class SelectLoop implements EventLoop
{
    /** @var array<int, array{0: resource, 1: callable(resource): void}> */
    private array $readListeners = [];

    /** @var array<int, array{0: resource, 1: callable(resource): void}> */
    private array $writeListeners = [];

    private bool $running = false;
    private TimerManager $timers;
    private EventLoopMetrics $metrics;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->timers = new TimerManager();
        $this->metrics = new EventLoopMetrics();
    }

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

    public function every(float $intervalSeconds, callable $callback): Timer
    {
        return $this->timers->every($intervalSeconds, $callback, $this->clock->now());
    }

    public function after(float $delaySeconds, callable $callback): Timer
    {
        return $this->timers->after($delaySeconds, $callback, $this->clock->now());
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

    public function metrics(): EventLoopMetrics
    {
        return $this->metrics;
    }

    /**
     * Runs a single wait-and-dispatch pass. Returns the number of streams
     * that were ready, or 0 if the wait timed out (or only a timer fired).
     */
    public function tick(?float $timeoutSeconds): int
    {
        $this->forgetClosedStreams();

        $read = array_map(static fn (array $entry) => $entry[0], $this->readListeners);
        $write = array_map(static fn (array $entry) => $entry[0], $this->writeListeners);

        $wait = $this->shorterWait($timeoutSeconds, $this->timers->nextDueIn($this->clock->now()));

        $waitStart = hrtime(true);
        $ready = $this->waitForReadiness($read, $write, $wait);
        $idleSeconds = (hrtime(true) - $waitStart) / 1_000_000_000;

        $busyStart = hrtime(true);
        $this->timers->tick($this->clock->now());

        // stream_select() narrows both arrays to whatever became ready, so
        // a pass that timed out simply has nothing to loop over - it needs
        // no early return of its own.
        foreach ($read as $stream) {
            $id = get_resource_id($stream);

            // is_resource() as well as the listener check: an earlier
            // callback in this very loop may have closed this stream (a
            // PUBLISH that drops a broken subscriber, a shutdown that
            // closes every connection at once), and handing a closed
            // stream to a listener is a TypeError, not a warning.
            if (is_resource($stream) && isset($this->readListeners[$id])) {
                ($this->readListeners[$id][1])($stream);
            }
        }

        foreach ($write as $stream) {
            $id = get_resource_id($stream);

            if (is_resource($stream) && isset($this->writeListeners[$id])) {
                ($this->writeListeners[$id][1])($stream);
            }
        }

        $busySeconds = (hrtime(true) - $busyStart) / 1_000_000_000;
        $this->metrics->recordIteration($busySeconds, $idleSeconds);

        return $ready;
    }

    /**
     * Drops streams that were closed by whoever owns them, before
     * stream_select() is handed one and raises a TypeError that takes the
     * whole process down.
     *
     * The loop does not own what it watches: RedisServer closes every
     * connection at once on shutdown, a connection is dropped from inside
     * a listener, a test closes a socket it made itself. Requiring each of
     * them to deregister first - on every path, including the ones that
     * throw - is a coupling the loop does not need: a closed stream is
     * never going to be ready again, so forgetting it is always right.
     */
    private function forgetClosedStreams(): void
    {
        foreach ($this->readListeners as $id => [$stream]) {
            if (!is_resource($stream)) {
                unset($this->readListeners[$id]);
            }
        }

        foreach ($this->writeListeners as $id => [$stream]) {
            if (!is_resource($stream)) {
                unset($this->writeListeners[$id]);
            }
        }
    }

    /**
     * Blocks until one of the registered streams is ready or $wait elapses,
     * and returns how many are - narrowing $read and $write to exactly
     * those, the way stream_select() reports its answer.
     *
     * With nothing registered there is nothing to select on, so the wait
     * becomes a plain sleep: the loop still has to come back for its
     * timers, which are then the only thing that can be due.
     *
     * @param list<resource> $read
     * @param list<resource> $write
     */
    private function waitForReadiness(array &$read, array &$write, ?float $wait): int
    {
        if ($read === [] && $write === []) {
            if ($wait !== null) {
                usleep((int) ($wait * 1_000_000));
            }

            return 0;
        }

        // stream_select() wants whole seconds plus a microsecond remainder
        // rather than one float; null for both means "wait indefinitely".
        $seconds = $wait === null ? null : (int) floor($wait);
        $microseconds = $wait === null ? null : (int) (($wait - $seconds) * 1_000_000);

        $except = null;
        $ready = @stream_select($read, $write, $except, $seconds, $microseconds);

        // False means the wait was cut short by a signal (EINTR) - which is
        // how a shutdown request reaches a loop sitting in select(). Nothing
        // is ready; the next pass waits again.
        return $ready === false ? 0 : $ready;
    }

    private function shorterWait(?float $a, ?float $b): ?float
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return min($a, $b);
    }
}
