<?php

declare(strict_types=1);

namespace App\Transaction;

use App\Command\Command;
use App\Connection\ClientConnection;

/**
 * Tracks each connection's queued commands between MULTI and EXEC/DISCARD.
 */
final class TransactionManager
{
    /** @var array<int, list<Command>> */
    private array $queued = [];

    public function begin(ClientConnection $connection): void
    {
        $this->queued[$connection->id()] = [];
    }

    public function isActive(ClientConnection $connection): bool
    {
        return isset($this->queued[$connection->id()]);
    }

    public function queue(ClientConnection $connection, Command $command): void
    {
        $this->queued[$connection->id()][] = $command;
    }

    public function discard(ClientConnection $connection): void
    {
        unset($this->queued[$connection->id()]);
    }

    /**
     * Removes and returns the connection's queued commands, ending its
     * transaction.
     *
     * @return list<Command>
     */
    public function drain(ClientConnection $connection): array
    {
        $commands = $this->queued[$connection->id()] ?? [];
        unset($this->queued[$connection->id()]);

        return $commands;
    }
}
