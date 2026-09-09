<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Writes info/warning to stdout and errors to stderr.
 */
final class ConsoleLogger implements Logger
{
    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(mixed $stdout = null, mixed $stderr = null)
    {
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
