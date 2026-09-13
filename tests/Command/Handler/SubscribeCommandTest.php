<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\SubscribeCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\PubSub\ChannelRegistry;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class SubscribeCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testSubscribesTheConnectionAndRepliesWithTheSubscriptionCount(): void
    {
        $registry = new ChannelRegistry();
        $connection = $this->createConnection();
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SUBSCRIBE'),
            RespValue::bulkString('news'),
        ]));

        $result = (new SubscribeCommand($registry))->handle($command, new InMemoryStore(), $connection);

        self::assertSame(RespType::Array, $result->type);
        self::assertSame('subscribe', $result->value[0]->value);
        self::assertSame('news', $result->value[1]->value);
        self::assertSame(1, $result->value[2]->value);
        self::assertSame([$connection], $registry->subscribers('news'));
    }

    public function testRejectsTheWrongNumberOfArguments(): void
    {
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('SUBSCRIBE')]));

        $result = (new SubscribeCommand(new ChannelRegistry()))
            ->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }
}
