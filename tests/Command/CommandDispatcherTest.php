<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Command;
use App\Command\CommandDispatcher;
use App\Command\CommandHandler;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Storage\Store;
use PHPUnit\Framework\TestCase;

final class CommandDispatcherTest extends TestCase
{
    public function testRoutesACommandToItsRegisteredHandler(): void
    {
        $dispatcher = new CommandDispatcher();
        $dispatcher->register('PING', new class implements CommandHandler {
            public function handle(Command $command, Store $store): RespValue
            {
                return RespValue::simpleString('PONG');
            }
        });

        $result = $dispatcher->dispatch($this->command('PING'), new InMemoryStore());

        self::assertSame('PONG', $result->value);
    }

    public function testRoutingIsCaseInsensitive(): void
    {
        $dispatcher = new CommandDispatcher();
        $dispatcher->register('ping', new class implements CommandHandler {
            public function handle(Command $command, Store $store): RespValue
            {
                return RespValue::simpleString('PONG');
            }
        });

        $result = $dispatcher->dispatch($this->command('PING'), new InMemoryStore());

        self::assertSame('PONG', $result->value);
    }

    public function testReturnsAnErrorForAnUnregisteredCommand(): void
    {
        $dispatcher = new CommandDispatcher();

        $result = $dispatcher->dispatch($this->command('UNKNOWN'), new InMemoryStore());

        self::assertSame(RespType::Error, $result->type);
    }

    public function testDefaultHandlersCoverTheBasicCommands(): void
    {
        $dispatcher = CommandDispatcher::withDefaultHandlers();
        $store = new InMemoryStore();

        $dispatcher->dispatch($this->command('SET', ['foo', 'bar']), $store);
        $get = $dispatcher->dispatch($this->command('GET', ['foo']), $store);

        self::assertSame('bar', $get->value);
    }

    /** @param list<string> $arguments */
    private function command(string $name, array $arguments = []): Command
    {
        $elements = array_map(RespValue::bulkString(...), [$name, ...$arguments]);

        return Command::fromRespValue(RespValue::array($elements));
    }
}
