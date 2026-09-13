<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Command;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandDispatcher;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Storage\Store;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class CommandDispatcherTest extends TestCase
{
    use CreatesTestConnections;

    public function testRoutesACommandToItsRegisteredHandler(): void
    {
        $dispatcher = new CommandDispatcher();
        $dispatcher->register('PING', new class implements CommandHandler {
            public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
            {
                return RespValue::simpleString('PONG');
            }
        });

        $result = $dispatcher->dispatch($this->command('PING'), new InMemoryStore(), $this->createConnection());

        self::assertSame('PONG', $result->value);
    }

    public function testRoutingIsCaseInsensitive(): void
    {
        $dispatcher = new CommandDispatcher();
        $dispatcher->register('ping', new class implements CommandHandler {
            public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
            {
                return RespValue::simpleString('PONG');
            }
        });

        $result = $dispatcher->dispatch($this->command('PING'), new InMemoryStore(), $this->createConnection());

        self::assertSame('PONG', $result->value);
    }

    public function testReturnsAnErrorForAnUnregisteredCommand(): void
    {
        $dispatcher = new CommandDispatcher();

        $result = $dispatcher->dispatch($this->command('UNKNOWN'), new InMemoryStore(), $this->createConnection());

        self::assertSame(RespType::Error, $result->type);
    }

    public function testKnowsReportsWhetherAHandlerIsRegistered(): void
    {
        $dispatcher = CommandDispatcher::withDefaultHandlers();

        self::assertTrue($dispatcher->knows('GET'));
        self::assertTrue($dispatcher->knows('get'));
        self::assertFalse($dispatcher->knows('NOSUCHCOMMAND'));
    }

    public function testDefaultHandlersCoverTheBasicCommands(): void
    {
        $dispatcher = CommandDispatcher::withDefaultHandlers();
        $store = new InMemoryStore();
        $connection = $this->createConnection();

        $dispatcher->dispatch($this->command('SET', ['foo', 'bar']), $store, $connection);
        $get = $dispatcher->dispatch($this->command('GET', ['foo']), $store, $connection);

        self::assertSame('bar', $get->value);
    }

    /** @param list<string> $arguments */
    private function command(string $name, array $arguments = []): Command
    {
        $elements = array_map(RespValue::bulkString(...), [$name, ...$arguments]);

        return Command::fromRespValue(RespValue::array($elements));
    }
}
