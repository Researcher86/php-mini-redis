<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Discards every message. Useful as a default when no logging is wanted,
 * e.g. in tests.
 */
final readonly class NullLogger implements Logger
{
    public function info(string $message): void
    {
    }

    public function warning(string $message): void
    {
    }

    public function error(string $message): void
    {
    }
}
