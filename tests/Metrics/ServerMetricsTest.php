<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\ServerMetrics;
use PHPUnit\Framework\TestCase;

final class ServerMetricsTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $metrics = new ServerMetrics();

        self::assertSame(0, $metrics->connectionsTotal());
        self::assertSame(0, $metrics->commandsProcessed());
        self::assertSame([], $metrics->commandsByType());
        self::assertSame(0, $metrics->bytesRead());
        self::assertSame(0, $metrics->bytesWritten());
        self::assertSame(0, $metrics->errors());
        self::assertSame(0, $metrics->keysExpired());
    }

    public function testRecordConnectionAccumulates(): void
    {
        $metrics = new ServerMetrics();

        $metrics->recordConnection();
        $metrics->recordConnection();

        self::assertSame(2, $metrics->connectionsTotal());
    }

    public function testRecordCommandTracksTotalsAndPerCommandCounts(): void
    {
        $metrics = new ServerMetrics();

        $metrics->recordCommand('GET');
        $metrics->recordCommand('GET');
        $metrics->recordCommand('SET');

        self::assertSame(3, $metrics->commandsProcessed());
        self::assertSame(['GET' => 2, 'SET' => 1], $metrics->commandsByType());
    }

    public function testRecordBytesReadAndWrittenAccumulate(): void
    {
        $metrics = new ServerMetrics();

        $metrics->recordBytesRead(10);
        $metrics->recordBytesRead(5);
        $metrics->recordBytesWritten(7);

        self::assertSame(15, $metrics->bytesRead());
        self::assertSame(7, $metrics->bytesWritten());
    }

    public function testRecordErrorAccumulates(): void
    {
        $metrics = new ServerMetrics();

        $metrics->recordError();
        $metrics->recordError();

        self::assertSame(2, $metrics->errors());
    }

    public function testRecordExpiredKeysAccumulates(): void
    {
        $metrics = new ServerMetrics();

        $metrics->recordExpiredKeys(3);
        $metrics->recordExpiredKeys(2);

        self::assertSame(5, $metrics->keysExpired());
    }
}
