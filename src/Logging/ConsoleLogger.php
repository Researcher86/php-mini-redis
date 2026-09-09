<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Writes info/warning to stdout and errors to stderr.
 */
final readonly class ConsoleLogger implements Logger
{
    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    public function __construct(
        /** @var resource|null */
        mixed $stdout = null,
        /** @var resource|null */
        mixed $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public function info(string $message): void
    {
        $this->write($this->stdout, $message);
    }

    public function warning(string $message): void
    {
        $this->write($this->stdout, $message);
    }

    public function error(string $message): void
    {
        $this->write($this->stderr, $message);
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $message): void
    {
        fwrite($stream, $message . PHP_EOL);
    }
}
