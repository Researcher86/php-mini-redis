<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

final readonly class PingCommand implements CommandHandler
{
    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if ($command->arguments === []) {
            return RespValue::simpleString('PONG');
        }

        return RespValue::bulkString($command->arguments[0]);
    }
}
