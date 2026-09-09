<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\DiscardCommand;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Tests\Support\CreatesTestConnections;
use App\Transaction\TransactionManager;
use PHPUnit\Framework\TestCase;

final class DiscardCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testDiscardsAnActiveTransactionAndRepliesOk(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();
        $transactions->begin($connection);
        $transactions->queue($connection, Command::fromRespValue(RespValue::array([RespValue::bulkString('PING')])));
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('DISCARD')]));

        $result = (new DiscardCommand($transactions))->handle($command, new InMemoryStore(), $connection);

        self::assertSame('OK', $result->value);
        self::assertFalse($transactions->isActive($connection));
    }

    public function testRejectsDiscardWithoutMulti(): void
    {
        $transactions = new TransactionManager();
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('DISCARD')]));

        $result = (new DiscardCommand($transactions))->handle($command, new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }
}
