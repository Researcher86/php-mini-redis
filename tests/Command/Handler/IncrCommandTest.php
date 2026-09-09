<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\IncrCommand;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Tests\Support\CreatesTestConnections;
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
