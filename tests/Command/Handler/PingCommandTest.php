<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\PingCommand;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class PingCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testRepliesWithPongWhenGivenNoArgument(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('PING')]));

        $result = (new PingCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame('PONG', $result->value);
    }

    public function testEchoesTheGivenArgument(): void
    {
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('PING'),
            RespValue::bulkString('hello'),
        ]));

        $result = (new PingCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame('hello', $result->value);
    }
}
