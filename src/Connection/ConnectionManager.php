<?php

declare(strict_types=1);

namespace PhpMiniCache\Connection;

/**
 * Tracks every currently connected client.
 */
final class ConnectionManager
{
    /** @var array<int, ClientConnection> */
    private array $connections = [];

    public function add(ClientConnection $connection): void
    {
        $this->connections[$connection->id()] = $connection;
    }

    public function remove(ClientConnection $connection): void
    {
        unset($this->connections[$connection->id()]);
    }

    public function count(): int
    {
        return count($this->connections);
    }

    /** @return array<int, ClientConnection> */
    public function all(): array
    {
        return $this->connections;
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }
}
