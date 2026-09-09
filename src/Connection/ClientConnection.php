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
    private ReadBuffer $readBuffer;
    private WriteBuffer $writeBuffer;
    private float $lastActivityAt;

    public function __construct(
        /** @var resource */
        private readonly mixed $socket,
    ) {
        $this->state = ConnectionState::New;
        $this->readBuffer = new ReadBuffer();
        $this->writeBuffer = new WriteBuffer();
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

    public function readBuffer(): ReadBuffer
    {
        return $this->readBuffer;
    }

    public function appendToReadBuffer(string $bytes): void
    {
        $this->readBuffer->append($bytes);
        $this->touch();
    }

    public function clearReadBuffer(): void
    {
        $this->readBuffer->clear();
    }

    public function writeBuffer(): WriteBuffer
    {
        return $this->writeBuffer;
    }

    public function appendToWriteBuffer(string $bytes): void
    {
        $this->writeBuffer->append($bytes);
        $this->touch();
    }

    public function clearWriteBuffer(): void
    {
        $this->writeBuffer->clear();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->state = ConnectionState::Closed;
    }
}
