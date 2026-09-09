<?php

declare(strict_types=1);

namespace App\Server;

use App\Connection\ClientConnection;
use App\Connection\ConnectionManager;
use App\Connection\ConnectionState;

/**
 * Phase 2: accepts TCP clients and represents each one as an explicit
 * ClientConnection.
 *
 * Reading, writing and the event loop are not implemented yet - every
 * accepted connection is simply held open and tracked.
 */
final class RedisServer
{
    private ServerSocket $socket;
    private ConnectionManager $connections;

    public function __construct(ServerConfig $config)
    {
        $this->socket = new ServerSocket($config);
        $this->connections = new ConnectionManager();
    }

    public function localAddress(): string
    {
        return $this->socket->localAddress();
    }

    /**
     * Blocks until a client connects (or the timeout elapses), and starts
     * tracking it.
     */
    public function acceptClient(?float $timeoutSeconds = null): ?ClientConnection
    {
        $socket = $this->socket->accept($timeoutSeconds);

        if ($socket === false) {
            return null;
        }

        $connection = new ClientConnection($socket);
        $connection->setState(ConnectionState::Connected);
        $this->connections->add($connection);

        return $connection;
    }

    public function connections(): ConnectionManager
    {
        return $this->connections;
    }

    public function connectedClientCount(): int
    {
        return $this->connections->count();
    }

    public function stop(): void
    {
        $this->connections->closeAll();
        $this->socket->close();
    }
}
