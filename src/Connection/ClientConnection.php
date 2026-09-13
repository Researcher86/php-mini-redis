<?php

declare(strict_types=1);

namespace PhpMiniCache\Connection;

use PhpMiniCache\Support\Clock;
use PhpMiniCache\Support\SystemClock;

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
        // The same clock the server checks idleness against. Two clocks -
        // one recording activity, one deciding what counts as idle - is a
        // timeout that cannot be tested deterministically, since a test can
        // only ever move one of them.
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->state = ConnectionState::New;
        $this->readBuffer = new ReadBuffer();
        $this->writeBuffer = new WriteBuffer();
        $this->lastActivityAt = $this->clock->now();
    }

    /**
     * The socket's resource id, which is what every per-connection registry
     * outside this class keys on (subscriptions, open transactions, the
     * paused-reading set) rather than passing the object around.
     *
     * Worth knowing: the id stays readable after the socket is closed, but
     * the number itself is reused by whatever the process opens next. A
     * registry that fails to drop a closed connection's entry does not just
     * leak it - it hands it to an unrelated connection later on.
     */
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


    public function lastActivityAt(): float
    {
        return $this->lastActivityAt;
    }

    /**
     * Every path that moves bytes in either direction counts as activity,
     * which is what Phase 19's idle timeout measures - so this is called
     * from each of them rather than left to the callers to remember.
     */
    private function touch(): void
    {
        $this->lastActivityAt = $this->clock->now();
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

    public function writeBuffer(): WriteBuffer
    {
        return $this->writeBuffer;
    }

    public function appendToWriteBuffer(string $bytes): void
    {
        $this->writeBuffer->append($bytes);
        $this->touch();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->state = ConnectionState::Closed;
    }
}
