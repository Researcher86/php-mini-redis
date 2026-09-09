<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\PublishCommand;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\PubSub\ChannelRegistry;
use App\Storage\InMemoryStore;
use App\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class PublishCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testDeliversTheMessageToEverySubscriberAndReturnsHowMany(): void
    {
        $registry = new ChannelRegistry();
        $subscriberA = $this->createConnection();
        $subscriberB = $this->createConnection();
        $registry->subscribe('news', $subscriberA);
        $registry->subscribe('news', $subscriberB);

        $delivered = [];
        $publish = new PublishCommand($registry, function (ClientConnection $connection, string $payload) use (&$delivered): void {
            $delivered[] = [$connection, $payload];
        });

        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('PUBLISH'),
            RespValue::bulkString('news'),
            RespValue::bulkString('hello'),
        ]));

        $result = $publish->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(2, $result->value);
        self::assertCount(2, $delivered);
        self::assertSame($subscriberA, $delivered[0][0]);
        self::assertSame("*3\r\n\$7\r\nmessage\r\n\$4\r\nnews\r\n\$5\r\nhello\r\n", $delivered[0][1]);
        self::assertSame($subscriberB, $delivered[1][0]);
    }

    public function testReturnsZeroWhenNobodyIsSubscribed(): void
    {
        $publish = new PublishCommand(new ChannelRegistry(), static function (): void {
            self::fail('No subscriber should be delivered to.');
        });

        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('PUBLISH'),
            RespValue::bulkString('news'),
            RespValue::bulkString('hello'),
        ]));

        $result = $publish->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(0, $result->value);
    }

    public function testRejectsTheWrongNumberOfArguments(): void
    {
        $publish = new PublishCommand(new ChannelRegistry(), static function (): void {
        });

        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('PUBLISH'),
            RespValue::bulkString('news'),
        ]));

        $result = $publish->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(\App\Protocol\RespType::Error, $result->type);
    }
}
