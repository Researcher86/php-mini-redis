<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Transaction;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PhpMiniCache\Transaction\TransactionManager;
use PHPUnit\Framework\TestCase;

final class TransactionManagerTest extends TestCase
{
    use CreatesTestConnections;

    public function testIsNotActiveUntilBegun(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();

        self::assertFalse($transactions->isActive($connection));
    }

    public function testBeginStartsAnEmptyTransaction(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();

        $transactions->begin($connection);

        self::assertTrue($transactions->isActive($connection));
        self::assertSame([], $transactions->drain($connection));
    }

    public function testQueueAccumulatesCommandsInOrder(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();
        $transactions->begin($connection);

        $set = $this->command('SET', ['foo', 'bar']);
        $get = $this->command('GET', ['foo']);
        $transactions->queue($connection, $set);
        $transactions->queue($connection, $get);

        self::assertSame([$set, $get], $transactions->drain($connection));
    }

    public function testDrainEndsTheTransaction(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();
        $transactions->begin($connection);
        $transactions->queue($connection, $this->command('PING'));

        $transactions->drain($connection);

        self::assertFalse($transactions->isActive($connection));
    }

    public function testDiscardEndsTheTransactionWithoutReturningTheCommands(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();
        $transactions->begin($connection);
        $transactions->queue($connection, $this->command('PING'));

        $transactions->discard($connection);

        self::assertFalse($transactions->isActive($connection));
    }

    public function testEachConnectionHasItsOwnTransaction(): void
    {
        $transactions = new TransactionManager();
        $a = $this->createConnection();
        $b = $this->createConnection();

        $transactions->begin($a);

        self::assertTrue($transactions->isActive($a));
        self::assertFalse($transactions->isActive($b));
    }

    /** @param list<string> $arguments */
    private function command(string $name, array $arguments = []): Command
    {
        $elements = array_map(RespValue::bulkString(...), [$name, ...$arguments]);

        return Command::fromRespValue(RespValue::array($elements));
    }
}
