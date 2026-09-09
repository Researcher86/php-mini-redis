<?php

declare(strict_types=1);

namespace App\Server;

use App\Connection\ClientConnection;
use App\Connection\ConnectionManager;
use App\Connection\ConnectionState;
use App\EventLoop\EventLoop;
use App\EventLoop\SelectLoop;

/**
 * Phase 3: accepts TCP clients through an EventLoop instead of blocking on
 * one client at a time, and represents each one as a ClientConnection.
 *
 * Reading and writing client data is not implemented yet - every accepted
 * connection is simply held open and tracked.
 */
final class RedisServer
{
    private ServerSocket $socket;
    private ConnectionManager $connections;
    private EventLoop $eventLoop;

    public function __construct(ServerConfig $config, ?EventLoop $eventLoop = null)
    {
        $this->socket = new ServerSocket($config);
        $this->connections = new ConnectionManager();
        $this->eventLoop = $eventLoop ?? new SelectLoop();
    }

    public function localAddress(): string
    {
        return $this->socket->localAddress();
    }

    /**
     * Blocks until a client connects (or the timeout elapses), and starts
     * tracking it. Useful outside the event loop, e.g. in tests.
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

    /**
     * Registers the listening socket with the event loop and runs it.
     *
     * @param (callable(ClientConnection): void)|null $onConnect
     */
    public function run(?callable $onConnect = null): void
    {
        $this->eventLoop->onReadable($this->socket->resource(), function () use ($onConnect): void {
            $connection = $this->acceptClient(0);

            if ($connection !== null && $onConnect !== null) {
                $onConnect($connection);
            }
        });

        $this->eventLoop->run();
    }

    public function stop(): void
    {
        $this->eventLoop->stop();
        $this->connections->closeAll();
        $this->socket->close();
    }
}
