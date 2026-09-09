<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\Storage\Store;

final readonly class SetCommand implements CommandHandler
{
    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        $arguments = $command->arguments;

        if (count($arguments) === 2) {
            [$key, $value] = $arguments;
            $store->set($key, $value);

            return RespValue::simpleString('OK');
        }

        if (count($arguments) === 4 && strtoupper($arguments[2]) === 'EX') {
            [$key, $value, , $ttl] = $arguments;

            if (preg_match('/^\d+$/', $ttl) !== 1) {
                return RespValue::error('ERR value is not an integer or out of range');
            }

            $store->set($key, $value, (int) $ttl);

            return RespValue::simpleString('OK');
        }

        return RespValue::error("ERR wrong number of arguments for 'set' command");
    }
}
