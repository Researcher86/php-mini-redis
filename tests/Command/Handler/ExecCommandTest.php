<?php

declare(strict_types=1);

namespace App\Tests\Command\Handler;

use App\Command\Command;
use App\Command\CommandDispatcher;
use App\Command\Handler\ExecCommand;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Tests\Support\CreatesTestConnections;
use App\Transaction\TransactionManager;
use PHPUnit\Framework\TestCase;

final class ExecCommandTest extends TestCase
{
    use CreatesTestConnections;

    public function testExecutesQueuedCommandsInOrderAndReturnsTheirResults(): void
    {
        $transactions = new TransactionManager();
        $dispatcher = CommandDispatcher::withDefaultHandlers();
        $store = new InMemoryStore();
        $connection = $this->createConnection();

        $transactions->begin($connection);
        $transactions->queue($connection, $this->command('SET', ['foo', 'bar']));
        $transactions->queue($connection, $this->command('GET', ['foo']));

        $command = $this->command('EXEC');
        $result = (new ExecCommand($transactions, $dispatcher))->handle($command, $store, $connection);

        self::assertSame(RespType::Array, $result->type);
        self::assertSame('OK', $result->value[0]->value);
        self::assertSame('bar', $result->value[1]->value);
        self::assertFalse($transactions->isActive($connection));
    }

    public function testRejectsExecWithoutMulti(): void
    {
        $transactions = new TransactionManager();
        $dispatcher = CommandDispatcher::withDefaultHandlers();

        $result = (new ExecCommand($transactions, $dispatcher))
            ->handle($this->command('EXEC'), new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }

    /** @param list<string> $arguments */
    private function command(string $name, array $arguments = []): Command
    {
        $elements = array_map(RespValue::bulkString(...), [$name, ...$arguments]);

        return Command::fromRespValue(RespValue::array($elements));
    }
}
