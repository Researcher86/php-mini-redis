<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\IncrCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PhpMiniCache\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class IncrCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testIncrementsAMissingKeyFromZero(): void
    {
        $store = new InMemoryStore();
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('INCR'),
            RespValue::bulkString('counter'),
        ]));

        $result = (new IncrCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(1, $result->value);
        self::assertSame('1', $store->get('counter'));
    }

    public function testIncrementsAnExistingIntegerValue(): void
    {
        $store = new InMemoryStore();
        $store->set('counter', '41');
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('INCR'),
            RespValue::bulkString('counter'),
        ]));

        $result = (new IncrCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(42, $result->value);
        self::assertSame('42', $store->get('counter'));
    }

    public function testIncrementingAnExpiringCounterLeavesItsTtlAlone(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);
        $store->set('hits', '1', ttlSeconds: 60);

        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('INCR'),
            RespValue::bulkString('hits'),
        ]));

        (new IncrCommand())->handle($command, $store, $this->createConnection());

        $clock->advance(59);
        self::assertSame('2', $store->get('hits'));

        // Counting a hit must not make a rate-limit key permanent.
        $clock->advance(1);
        self::assertNull($store->get('hits'));
    }

    public function testRejectsANonIntegerValue(): void
    {
        $store = new InMemoryStore();
        $store->set('name', 'Tanat');
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('INCR'),
            RespValue::bulkString('name'),
        ]));

        $result = (new IncrCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }

    public function testRejectsAValueWhoseDigitsExceedThePlatformIntegerRange(): void
    {
        $store = new InMemoryStore();
        $store->set('counter', (string) PHP_INT_MAX . '9');
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('INCR'),
            RespValue::bulkString('counter'),
        ]));

        $result = (new IncrCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
        self::assertSame('ERR value is not an integer or out of range', $result->value);
    }

    public function testRejectsAnIncrementThatWouldOverflow(): void
    {
        $store = new InMemoryStore();
        $store->set('counter', (string) PHP_INT_MAX);
        $command = Command::fromRespValue(RespValue::array([
            RespValue::bulkString('INCR'),
            RespValue::bulkString('counter'),
        ]));

        $result = (new IncrCommand())->handle($command, $store, $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
        self::assertSame('ERR increment or decrement would overflow', $result->value);
        self::assertSame((string) PHP_INT_MAX, $store->get('counter'), 'A rejected INCR must not mutate the stored value.');
    }
}
