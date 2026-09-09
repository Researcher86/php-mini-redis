<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\PingCommand;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use PHPUnit\Framework\TestCase;

final class PingCommandTest extends TestCase
{
    public function testRepliesWithPongWhenGivenNoArgument(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('PING')]));

        $result = (new PingCommand())->handle($command, new InMemoryStore());

        self::assertSame('PONG', $result->value);
    }

    public function testEchoesTheGivenArgument(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('PING'),
            RespValue::bulkString('hello'),
        ]));

        $result = (new PingCommand())->handle($command, new InMemoryStore());

        self::assertSame('hello', $result->value);
    }
}
