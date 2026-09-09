<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\SetCommand;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use PHPUnit\Framework\TestCase;

final class SetCommandTest extends TestCase
{
    public function testStoresTheValueAndRepliesOk(): void
    {
        $store = new InMemoryStore();
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('name'),
            RespValue::bulkString('Tanat'),
        ]));

        $result = (new SetCommand())->handle($command, $store);

        self::assertSame(RespType::SimpleString, $result->type);
        self::assertSame('OK', $result->value);
        self::assertSame('Tanat', $store->get('name'));
    }

    public function testRejectsTheWrongNumberOfArguments(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('name'),
        ]));

        $result = (new SetCommand())->handle($command, new InMemoryStore());

        self::assertSame(RespType::Error, $result->type);
    }

    public function testStoresTheValueWithATtlWhenGivenEx(): void
    {
        $now = 1000.0;
        $store = new InMemoryStore(static function () use (&$now): float {
            return $now;
        });
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('session'),
            RespValue::bulkString('abc'),
            RespValue::bulkString('EX'),
            RespValue::bulkString('60'),
        ]));

        $result = (new SetCommand())->handle($command, $store);

        self::assertSame('OK', $result->value);
        self::assertSame('abc', $store->get('session'));

        $now += 60;
        self::assertNull($store->get('session'));
    }

    public function testRejectsANonIntegerExValue(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('session'),
            RespValue::bulkString('abc'),
            RespValue::bulkString('EX'),
            RespValue::bulkString('soon'),
        ]));

        $result = (new SetCommand())->handle($command, new InMemoryStore());

        self::assertSame(RespType::Error, $result->type);
    }
}
