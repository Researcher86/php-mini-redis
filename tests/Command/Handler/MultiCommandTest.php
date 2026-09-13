<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\MultiCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PhpMiniCache\Transaction\TransactionManager;
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
