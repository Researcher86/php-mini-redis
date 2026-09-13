<?php

declare(strict_types=1);

namespace PhpMiniCache\Connection;

/**
 * Accumulates bytes read from a socket.
 *
 * A single TCP read is not guaranteed to contain a whole command, so
 * incoming bytes are appended here until a parser further up the stack
 * decides enough has arrived.
 */
final class ReadBuffer
{
    private string $buffer = '';

    public function append(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function contents(): string
    {
        return $this->buffer;
    }

    public function length(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Removes and returns the first $length bytes.
     */
    public function consume(int $length): string
    {
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $chunk;
    }
}
