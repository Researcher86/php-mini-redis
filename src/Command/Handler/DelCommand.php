<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\Storage\Store;

final readonly class DelCommand implements CommandHandler
{
    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if ($command->arguments === []) {
            return RespValue::error("ERR wrong number of arguments for 'del' command");
        }

        $deleted = 0;

        foreach ($command->arguments as $key) {
            if ($store->delete($key)) {
                $deleted++;
            }
        }

        return RespValue::integer($deleted);
    }
}
