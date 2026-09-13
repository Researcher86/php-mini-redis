<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

final readonly class GetCommand implements CommandHandler
{
    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if (count($command->arguments) !== 1) {
            return RespValue::error("ERR wrong number of arguments for 'get' command");
        }

        $value = $store->get($command->arguments[0]);

        return RespValue::bulkString($value === null ? null : (string) $value);
    }
}
