<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

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

            if (preg_match('/^-?\d+$/', $ttl) !== 1) {
                return RespValue::error('ERR value is not an integer or out of range');
            }

            $seconds = (int) $ttl;

            // Zero and negative are refused rather than obeyed, and so is a
            // number too large to survive the cast (which silently clamps to
            // PHP_INT_MAX). Storing a key that is already expired the moment
            // it is written answers +OK for a write nothing can ever read -
            // real Redis refuses the same way.
            if ($seconds <= 0 || (string) $seconds !== $ttl) {
                return RespValue::error("ERR invalid expire time in 'set' command");
            }

            $store->set($key, $value, $seconds);

            return RespValue::simpleString('OK');
        }

        return RespValue::error("ERR wrong number of arguments for 'set' command");
    }
}
