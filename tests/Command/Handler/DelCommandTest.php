<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\DelCommand;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use PHPUnit\Framework\TestCase;

final class DelCommandTest extends TestCase
{
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

        $result = (new DelCommand())->handle($command, $store);

        self::assertSame(2, $result->value);
        self::assertFalse($store->has('a'));
        self::assertFalse($store->has('b'));
    }

    public function testRejectsMissingArguments(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('DEL')]));

        $result = (new DelCommand())->handle($command, new InMemoryStore());

        self::assertSame(\App\Protocol\RespType::Error, $result->type);
    }
}
