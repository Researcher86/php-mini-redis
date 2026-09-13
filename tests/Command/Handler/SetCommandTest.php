<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\SetCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PhpMiniCache\Tests\Support\FakeClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SetCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testStoresTheValueAndRepliesOk(): void
    {
        $store = new InMemoryStore();
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('name'),
            RespValue::bulkString('Tanat'),
        ]));

        $result = (new SetCommand())->handle($command, $store, $this->createConnection());

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

        $result = (new SetCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }

    public function testStoresTheValueWithATtlWhenGivenEx(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('session'),
            RespValue::bulkString('abc'),
            RespValue::bulkString('EX'),
            RespValue::bulkString('60'),
        ]));

        $result = (new SetCommand())->handle($command, $store, $this->createConnection());

        self::assertSame('OK', $result->value);
        self::assertSame('abc', $store->get('session'));

        $clock->advance(60);
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

        $result = (new SetCommand())->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }

    /**
     * An expire time that cannot produce a readable key: zero and negative
     * are already in the past, and a number too big for the platform's
     * integer silently clamps to something the client never asked for.
     */
    #[DataProvider('invalidExpireTimes')]
    public function testRejectsAnExpireTimeThatWouldNeverProduceAReadableKey(string $ttl): void
    {
        $store = new InMemoryStore();
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('session'),
            RespValue::bulkString('abc'),
            RespValue::bulkString('EX'),
            RespValue::bulkString($ttl),
        ]));

        $result = (new SetCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
        self::assertSame("ERR invalid expire time in 'set' command", $result->value);

        // Not written at all, rather than written and instantly unreadable.
        self::assertFalse($store->has('session'));
    }

    /** @return array<string, array{string}> */
    public static function invalidExpireTimes(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'past the platform integer' => ['99999999999999999999'],
        ];
    }
}
