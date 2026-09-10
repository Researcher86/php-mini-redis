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
     *
     * A repeating timer's next run is $now plus its interval, not its
     * previous due time plus its interval: a loop held up by a slow pass
     * (or a long callback) resumes the interval from here instead of firing
     * repeatedly to catch up on the runs it missed. For a TTL sweep or an
     * idle check, "again in a second" is the point; "a second late, so run
     * five times now" is not.
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

        // Cancelled timers are dropped here, after the loop, rather than as
        // they are cancelled: a callback may cancel its own timer (the
        // shutdown checker does exactly that), and removing an element from
        // the array being iterated is how that turns into a bug.
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (Timer $timer): bool => !$timer->isCancelled(),
        ));
    }
}
