<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\GetCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
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
