<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\ExistsCommand;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use PHPUnit\Framework\TestCase;

final class ExistsCommandTest extends TestCase
{
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

        $result = (new ExistsCommand())->handle($command, $store);

        self::assertSame(2, $result->value);
    }

    public function testRejectsMissingArguments(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('EXISTS')]));

        $result = (new ExistsCommand())->handle($command, new InMemoryStore());

        self::assertSame(\App\Protocol\RespType::Error, $result->type);
    }
}
