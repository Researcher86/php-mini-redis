<?php

declare(strict_types=1);

namespace PhpMiniCache\EventLoop;

/**
 * A scheduled callback: fires once after $interval seconds, or repeatedly
 * every $interval seconds until cancelled.
 */
final class Timer
{
    private bool $cancelled = false;

    /**
     * @param callable(): void $callback
     */
    public function __construct(
        public float $nextRunAt,
        public readonly float $interval,
        public readonly mixed $callback,
        public readonly bool $repeating,
    ) {
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
