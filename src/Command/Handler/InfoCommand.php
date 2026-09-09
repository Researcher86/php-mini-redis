<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Connection\ConnectionManager;
use App\Metrics\ServerMetrics;
use App\Protocol\RespValue;
use App\Storage\Store;

/**
 * A small subset of real Redis's INFO: one bulk string of "key:value"
 * lines, enough to see the server is alive and doing something.
 */
final readonly class InfoCommand implements CommandHandler
{
    public function __construct(
        private ServerMetrics $metrics,
        private ConnectionManager $connections,
    ) {
    }

    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        $lines = [
            'connected_clients:' . $this->connections->count(),
            'total_connections_received:' . $this->metrics->connectionsTotal(),
            'total_commands_processed:' . $this->metrics->commandsProcessed(),
            'total_bytes_read:' . $this->metrics->bytesRead(),
            'total_bytes_written:' . $this->metrics->bytesWritten(),
            'total_errors:' . $this->metrics->errors(),
            'expired_keys:' . $this->metrics->keysExpired(),
        ];

        foreach ($this->metrics->commandsByType() as $name => $count) {
            $lines[] = sprintf('cmdstat_%s:calls=%d', strtolower($name), $count);
        }

        return RespValue::bulkString(implode("\r\n", $lines) . "\r\n");
    }
}
