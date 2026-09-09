<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\GetCommand;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class GetCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testReturnsTheStoredValue(): void
    {
        $store = new InMemoryStore();
        $store->set('name', 'Tanat');
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('GET'),
            RespValue::bulkString('name'),
        ]));

        $result = (new GetCommand())->handle($command, $store, $this->createConnection());

        self::assertSame('Tanat', $result->value);
    }

    public function testReturnsANullBulkStringForAMissingKey(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('GET'),
            RespValue::bulkString('missing'),
        ]));

        $result = (new GetCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertNull($result->value);
    }

    public function testRejectsTheWrongNumberOfArguments(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('GET')]));

        $result = (new GetCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }
}
