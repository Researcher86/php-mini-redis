<?php

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Logging\ConsoleLogger;
use PHPUnit\Framework\TestCase;

final class ConsoleLoggerTest extends TestCase
{
    public function testInfoAndWarningAreWrittenToStdout(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $logger = new ConsoleLogger($stdout, $stderr);
        $logger->info('hello');
        $logger->warning('careful');

        self::assertSame("hello\ncareful\n", $this->contentsOf($stdout));
        self::assertSame('', $this->contentsOf($stderr));
    }

    public function testErrorIsWrittenToStderr(): void
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $logger = new ConsoleLogger($stdout, $stderr);
        $logger->error('boom');

        self::assertSame('', $this->contentsOf($stdout));
        self::assertSame("boom\n", $this->contentsOf($stderr));
    }

    /** @param resource $stream */
    private function contentsOf(mixed $stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }
}
