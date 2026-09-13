<?php

declare(strict_types=1);

namespace PhpMiniCache\Command;

use PhpMiniCache\Command\Handler\DelCommand;
use PhpMiniCache\Command\Handler\ExistsCommand;
use PhpMiniCache\Command\Handler\GetCommand;
use PhpMiniCache\Command\Handler\IncrCommand;
use PhpMiniCache\Command\Handler\PingCommand;
use PhpMiniCache\Command\Handler\SetCommand;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

/**
 * Routes a Command to the handler registered for its name.
 */
final class CommandDispatcher
{
    /** @var array<string, CommandHandler> */
    private array $handlers = [];

    public function register(string $name, CommandHandler $handler): void
    {
        $this->handlers[strtoupper($name)] = $handler;
    }

    public function knows(string $name): bool
    {
        return isset($this->handlers[strtoupper($name)]);
    }

    public function dispatch(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        $handler = $this->handlers[$command->name] ?? null;

        if ($handler === null) {
            return RespValue::error(sprintf("ERR unknown command '%s'", strtolower($command->name)));
        }

        return $handler->handle($command, $store, $connection);
    }

    public static function withDefaultHandlers(): self
    {
        $dispatcher = new self();
        $dispatcher->register('PING', new PingCommand());
        $dispatcher->register('SET', new SetCommand());
        $dispatcher->register('GET', new GetCommand());
        $dispatcher->register('DEL', new DelCommand());
        $dispatcher->register('EXISTS', new ExistsCommand());
        $dispatcher->register('INCR', new IncrCommand());

        return $dispatcher;
    }
}
