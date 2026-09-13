<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\Handler\DiscardCommand;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PhpMiniCache\Transaction\TransactionManager;
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
