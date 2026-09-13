<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

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

        // Rewriting the value must not extend the key's life: a counter set
        // with a TTL stays on that TTL as it is incremented, as it does in
        // real Redis. A key that is not there yet is written as a new one,
        // with no expiration.
        if (!$store->setKeepingTtl($command->arguments[0], (string) $number)) {
            $store->set($command->arguments[0], (string) $number);
        }

        return RespValue::integer($number);
    }
}
