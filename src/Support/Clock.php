<?php

declare(strict_types=1);

namespace PhpMiniCache\Support;

/**
 * Abstracts "what time is it" (matching microtime(true)'s float-seconds
 * convention, used throughout this codebase) behind an interface, so
 * anything that computes a deadline or measures elapsed time can be driven
 * deterministically in tests instead of depending on the real system clock.
 */
interface Clock
{
    public function now(): float;
}
