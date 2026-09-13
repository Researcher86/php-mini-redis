<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandException;
use PhpMiniCache\Protocol\RespValue;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    public function testBuildsACommandFromAnArrayOfBulkStrings(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('foo'),
            RespValue::bulkString('bar'),
        ]));

        self::assertSame('SET', $command->name);
        self::assertSame(['foo', 'bar'], $command->arguments);
    }

    public function testNormalizesTheCommandNameToUppercase(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('get'),
            RespValue::bulkString('foo'),
        ]));

        self::assertSame('GET', $command->name);
    }

    public function testACommandWithoutArgumentsIsAllowed(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('PING'),
        ]));

        self::assertSame('PING', $command->name);
        self::assertSame([], $command->arguments);
    }

    public function testRejectsANonArrayValue(): void
    {
        $this->expectException(CommandException::class);

        Command::fromRespValue(RespValue::simpleString('PING'));
    }

    public function testRejectsANullArray(): void
    {
        $this->expectException(CommandException::class);

        Command::fromRespValue(RespValue::array(null));
    }

    public function testRejectsAnEmptyArray(): void
    {
        $this->expectException(CommandException::class);

        Command::fromRespValue(RespValue::array([]));
    }

    public function testRejectsANonBulkStringElement(): void
    {
        $this->expectException(CommandException::class);

        Command::fromRespValue(RespValue::array([
            RespValue::integer(1),
        ]));
    }

    public function testRejectsANullBulkStringElement(): void
    {
        $this->expectException(CommandException::class);

        Command::fromRespValue(RespValue::array([
            RespValue::bulkString(null),
        ]));
    }
}
