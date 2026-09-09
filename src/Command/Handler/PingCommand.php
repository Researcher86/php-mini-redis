<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Protocol\RespValue;
use App\Storage\Store;

final class PingCommand implements CommandHandler
{
    public function handle(Command $command, Store $store): RespValue
    {
        if ($command->arguments === []) {
            return RespValue::simpleString('PONG');
        }

        return RespValue::bulkString($command->arguments[0]);
    }
}
