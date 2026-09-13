<?php

declare(strict_types=1);

namespace PhpMiniCache\EventLoop;

/**
 * Observability for the event loop itself: how many wait/dispatch passes it
 * has made, how much of that was actually busy (dispatching callbacks)
 * versus idle (waiting in select), and the longest single busy stretch -
 * a direct measure of how long the loop can be held away from servicing
 * I/O and timers. Recording it never changes loop behavior.
 */
final class EventLoopMetrics
{
    private int $iterations = 0;
    private float $busySeconds = 0.0;
    private float $idleSeconds = 0.0;
    private float $maxLagSeconds = 0.0;

    /**
     * Records one completed wait-and-dispatch pass.
     */
    public function recordIteration(float $busySeconds, float $idleSeconds): void
    {
        $this->iterations++;
        $this->busySeconds += $busySeconds;
        $this->idleSeconds += $idleSeconds;
        $this->maxLagSeconds = max($this->maxLagSeconds, $busySeconds);
    }

    public function iterations(): int
    {
        return $this->iterations;
    }

    public function busySeconds(): float
    {
        return $this->busySeconds;
    }

    public function idleSeconds(): float
    {
        return $this->idleSeconds;
    }

    /**
     * The longest the loop has ever been busy in a single pass - i.e. the
     * furthest it has fallen behind on servicing sockets and timers.
     */
    public function maxLagSeconds(): float
    {
        return $this->maxLagSeconds;
    }
}
