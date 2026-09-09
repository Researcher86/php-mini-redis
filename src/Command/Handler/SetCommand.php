<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Protocol\RespValue;
use App\Storage\Store;

final class SetCommand implements CommandHandler
{
    public function handle(Command $command, Store $store): RespValue
    {
        if (count($command->arguments) !== 2) {
            return RespValue::error("ERR wrong number of arguments for 'set' command");
        }

        [$key, $value] = $command->arguments;
        $store->set($key, $value);

        return RespValue::simpleString('OK');
    }
}
