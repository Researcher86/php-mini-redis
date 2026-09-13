<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\ExistsCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class ExistsCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testCountsExistingKeysAmongTheArguments(): void
    {
        $store = new InMemoryStore();
        $store->set('a', '1');
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('EXISTS'),
            RespValue::bulkString('a'),
            RespValue::bulkString('a'),
            RespValue::bulkString('missing'),
        ]));

        $result = (new ExistsCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(2, $result->value);
    }

    public function testRejectsMissingArguments(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('EXISTS')]));

        $result = (new ExistsCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }
}
