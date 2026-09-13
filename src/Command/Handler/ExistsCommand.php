<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

final readonly class ExistsCommand implements CommandHandler
{
    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if ($command->arguments === []) {
            return RespValue::error("ERR wrong number of arguments for 'exists' command");
        }

        $count = 0;

        foreach ($command->arguments as $key) {
            if ($store->has($key)) {
                $count++;
            }
        }

        return RespValue::integer($count);
    }
}
