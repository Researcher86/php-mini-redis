<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\Handler\MultiCommand;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Tests\Support\CreatesTestConnections;
use App\Transaction\TransactionManager;
use PHPUnit\Framework\TestCase;

final class MultiCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testBeginsATransactionAndRepliesOk(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('MULTI')]));

        $result = (new MultiCommand($transactions))->handle($command, new InMemoryStore(), $connection);

        self::assertSame('OK', $result->value);
        self::assertTrue($transactions->isActive($connection));
    }

    public function testRejectsNestedMulti(): void
    {
        $transactions = new TransactionManager();
        $connection = $this->createConnection();
        $transactions->begin($connection);
        $command = Command::fromRespValue(RespValue::array([RespValue::bulkString('MULTI')]));

        $result = (new MultiCommand($transactions))->handle($command, new InMemoryStore(), $connection);

        self::assertSame(RespType::Error, $result->type);
    }
}
