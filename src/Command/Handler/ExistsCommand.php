<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\Storage\Store;

final class ExistsCommand implements CommandHandler
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
