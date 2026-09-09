<?php

declare(strict_types=1);

namespace App\Connection;

/**
 * Queues bytes waiting to be written to a socket.
 *
 * A single write is not guaranteed to flush everything at once, so unsent
 * bytes stay here until the socket becomes writable again.
 */
final class WriteBuffer
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

    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }

    /**
     * Removes and returns the first $length bytes, e.g. after a partial
     * write.
     */
    public function consume(int $length): string
    {
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $chunk;
    }
}
