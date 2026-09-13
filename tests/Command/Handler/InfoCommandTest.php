<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\InfoCommand;
use PhpMiniCache\Connection\ConnectionManager;
use PhpMiniCache\EventLoop\EventLoopMetrics;
use PhpMiniCache\Metrics\ServerMetrics;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class InfoCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testReportsConnectionAndCommandCounts(): void
    {
        $metrics = new ServerMetrics();
        $metrics->recordConnection();
        $metrics->recordCommand('GET');
        $metrics->recordCommand('GET');
        $metrics->recordBytesRead(100);
        $metrics->recordBytesWritten(50);
        $metrics->recordError();
        $metrics->recordExpiredKeys(4);

        $eventLoop = new EventLoopMetrics();
        $eventLoop->recordIteration(0.5, 1.5);
        $eventLoop->recordIteration(0.25, 0.25);

        $connections = new ConnectionManager();
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('INFO')]));

        $result = (new InfoCommand($metrics, $connections, $eventLoop))->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::BulkString, $result->type);
        self::assertStringContainsString('connected_clients:0', $result->value);
        self::assertStringContainsString('total_connections_received:1', $result->value);
        self::assertStringContainsString('total_commands_processed:2', $result->value);
        self::assertStringContainsString('total_bytes_read:100', $result->value);
        self::assertStringContainsString('total_bytes_written:50', $result->value);
        self::assertStringContainsString('total_errors:1', $result->value);
        self::assertStringContainsString('expired_keys:4', $result->value);
        self::assertStringContainsString('eventloop_iterations:2', $result->value);
        self::assertStringContainsString('eventloop_busy_sec:0.75', $result->value);
        self::assertStringContainsString('eventloop_idle_sec:1.75', $result->value);
        self::assertStringContainsString('eventloop_max_lag_sec:0.5', $result->value);
        self::assertStringContainsString('cmdstat_get:calls=2', $result->value);
    }

    public function testConnectedClientsReflectsTheConnectionManager(): void
    {
        $connections = new ConnectionManager();
        $connections->add($this->createConnection());
        $connections->add($this->createConnection());

        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('INFO')]));
        $result = (new InfoCommand(new ServerMetrics(), $connections, new EventLoopMetrics()))
            ->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertStringContainsString('connected_clients:2', $result->value);
    }
}
