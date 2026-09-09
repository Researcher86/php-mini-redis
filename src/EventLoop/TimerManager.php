<?php

declare(strict_types=1);

namespace App\EventLoop;

/**
 * Tracks scheduled timers and fires the ones that are due.
 */
final class TimerManager
{
    /** @var list<Timer> */
    private array $timers = [];

    /**
     * @param callable(): void $callback
     */
    public function every(float $intervalSeconds, callable $callback, float $now): Timer
    {
        $timer = new Timer($now + $intervalSeconds, $intervalSeconds, $callback, repeating: true);
        $this->timers[] = $timer;

        return $timer;
    }

    /**
     * @param callable(): void $callback
     */
    public function after(float $delaySeconds, callable $callback, float $now): Timer
    {
        $timer = new Timer($now + $delaySeconds, $delaySeconds, $callback, repeating: false);
        $this->timers[] = $timer;

        return $timer;
    }

    public function isEmpty(): bool
    {
        return $this->timers === [];
    }

    /**
     * Seconds until the next timer is due, or null if there are none.
     */
    public function nextDueIn(float $now): ?float
    {
        if ($this->timers === []) {
            return null;
        }

        $earliest = min(array_map(static fn (Timer $timer): float => $timer->nextRunAt, $this->timers));

        return max(0.0, $earliest - $now);
    }

    /**
     * Fires every timer whose time has come, rescheduling repeating ones.
     */
    public function tick(float $now): void
    {
        foreach ($this->timers as $timer) {
            if ($timer->isCancelled() || $timer->nextRunAt > $now) {
                continue;
            }

            ($timer->callback)();

            if ($timer->repeating && !$timer->isCancelled()) { // @phpstan-ignore booleanNot.alwaysTrue (a callback may cancel its own timer)
                $timer->nextRunAt = $now + $timer->interval;
            } else {
                $timer->cancel();
            }
        }

        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (Timer $timer): bool => !$timer->isCancelled(),
        ));
    }
}
