<?php

declare(strict_types=1);

namespace App\Server;

/**
 * Phase 1: accepts TCP clients and keeps track of them.
 *
 * Reading, writing and the event loop are not implemented yet - every
 * accepted connection is simply held open and counted.
 */
final class RedisServer
{
    private ServerSocket $socket;

    /** @var array<int, resource> */
    private array $clients = [];

    public function __construct(ServerConfig $config)
    {
        $this->socket = new ServerSocket($config);
    }

    public function localAddress(): string
    {
        return $this->socket->localAddress();
    }

    /**
     * Blocks until a client connects (or the timeout elapses), and starts
     * tracking it.
     */
    public function acceptClient(?float $timeoutSeconds = null): bool
    {
        $connection = $this->socket->accept($timeoutSeconds);

        if ($connection === false) {
            return false;
        }

        $this->clients[get_resource_id($connection)] = $connection;

        return true;
    }

    public function connectedClientCount(): int
    {
        return count($this->clients);
    }

    public function stop(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }

        $this->clients = [];
        $this->socket->close();
    }
}
