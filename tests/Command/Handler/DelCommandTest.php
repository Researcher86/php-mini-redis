<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\DelCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class DelCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testDeletesExistingKeysAndCountsThem(): void
    {
        $store = new InMemoryStore();
        $store->set('a', '1');
        $store->set('b', '2');
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('DEL'),
            RespValue::bulkString('a'),
            RespValue::bulkString('b'),
            RespValue::bulkString('missing'),
        ]));

        $result = (new DelCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(2, $result->value);
        self::assertFalse($store->has('a'));
        self::assertFalse($store->has('b'));
    }

    public function testRejectsMissingArguments(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('DEL')]));

        $result = (new DelCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }
}
