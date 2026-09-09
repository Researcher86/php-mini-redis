<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\Storage\Store;

final readonly class IncrCommand implements CommandHandler
{
    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if (count($command->arguments) !== 1) {
            return RespValue::error("ERR wrong number of arguments for 'incr' command");
        }

        $current = $store->get($command->arguments[0]);

        if ($current === null) {
            $number = 0;
        } elseif (is_string($current) && preg_match('/^-?\d+$/', $current) === 1) {
            $number = (int) $current;

            // (int) silently clamps out-of-range digits to PHP_INT_MAX /
            // PHP_INT_MIN, so compare back: a value that does not survive
            // the round-trip is out of range, same rule as real Redis.
            if ((string) $number !== $current) {
                return RespValue::error('ERR value is not an integer or out of range');
            }
        } else {
            return RespValue::error('ERR value is not an integer or out of range');
        }

        if ($number === PHP_INT_MAX) {
            return RespValue::error('ERR increment or decrement would overflow');
        }

        $number++;
        $store->set($command->arguments[0], (string) $number);

        return RespValue::integer($number);
    }
}
