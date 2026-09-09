<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\InfoCommand;
use App\Connection\ConnectionManager;
use App\Metrics\ServerMetrics;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Tests\Support\CreatesTestConnections;
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

        $connections = new ConnectionManager();
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('INFO')]));

        $result = (new InfoCommand($metrics, $connections))->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::BulkString, $result->type);
        self::assertStringContainsString('connected_clients:0', $result->value);
        self::assertStringContainsString('total_connections_received:1', $result->value);
        self::assertStringContainsString('total_commands_processed:2', $result->value);
        self::assertStringContainsString('total_bytes_read:100', $result->value);
        self::assertStringContainsString('total_bytes_written:50', $result->value);
        self::assertStringContainsString('total_errors:1', $result->value);
        self::assertStringContainsString('expired_keys:4', $result->value);
        self::assertStringContainsString('cmdstat_get:calls=2', $result->value);
    }

    public function testConnectedClientsReflectsTheConnectionManager(): void
    {
        $connections = new ConnectionManager();
        $connections->add($this->createConnection());
        $connections->add($this->createConnection());

        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('INFO')]));
        $result = (new InfoCommand(new ServerMetrics(), $connections))
            ->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertStringContainsString('connected_clients:2', $result->value);
    }
}
