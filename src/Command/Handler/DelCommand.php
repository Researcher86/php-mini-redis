<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

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
