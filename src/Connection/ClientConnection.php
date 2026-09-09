<?php

declare(strict_types=1);

namespace App\Connection;

/**
 * Represents one connected client: its socket, its buffers, its state and
 * when it was last active.
 */
final class ClientConnection
{
    private ConnectionState $state;
    private string $readBuffer = '';
    private string $writeBuffer = '';
    private float $lastActivityAt;

    /**
     * @param resource $socket
     */
    public function __construct(private readonly mixed $socket)
    {
        $this->state = ConnectionState::New;
        $this->lastActivityAt = microtime(true);
    }

    public function id(): int
    {
        return get_resource_id($this->socket);
    }

    /**
     * @return resource
     */
    public function socket(): mixed
    {
        return $this->socket;
    }

    public function state(): ConnectionState
    {
        return $this->state;
    }

    public function setState(ConnectionState $state): void
    {
        $this->state = $state;
        $this->touch();
    }

    public function touch(): void
    {
        $this->lastActivityAt = microtime(true);
    }

    public function lastActivityAt(): float
    {
        return $this->lastActivityAt;
    }

    public function readBuffer(): string
    {
        return $this->readBuffer;
    }

    public function appendToReadBuffer(string $bytes): void
    {
        $this->readBuffer .= $bytes;
        $this->touch();
    }

    public function clearReadBuffer(): void
    {
        $this->readBuffer = '';
    }

    public function writeBuffer(): string
    {
        return $this->writeBuffer;
    }

    public function appendToWriteBuffer(string $bytes): void
    {
        $this->writeBuffer .= $bytes;
        $this->touch();
    }

    public function clearWriteBuffer(): void
    {
        $this->writeBuffer = '';
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->state = ConnectionState::Closed;
    }
}
