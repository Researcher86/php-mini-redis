<?php

declare(strict_types=1);

namespace PhpMiniCache\Connection;

/**
 * Queues bytes waiting to be written to a socket.
 *
 * A single write is not guaranteed to flush everything at once, so unsent
 * bytes stay here until the socket becomes writable again.
 *
 * Consuming is a moving offset rather than a re-slice. A slow reader's
 * backlog can run to megabytes and drains a few kilobytes per writable
 * event; rebuilding the remainder on each of those is O(backlog) copying
 * per write, which turns one slow client into real CPU cost. The offset
 * makes a consume O(1), and the string is rebuilt only when the dead
 * prefix in front of it is worth reclaiming.
 */
final class WriteBuffer
{
    /**
     * How much already-written data may sit in front of the offset before
     * the string is rebuilt without it. Small enough that a connection
     * cannot hold an arbitrary amount of dead bytes, large enough that the
     * rebuild happens once every many writes.
     */
    private const int COMPACTION_THRESHOLD_BYTES = 64 * 1024;

    private string $buffer = '';

    /** Where the unwritten bytes start; everything before it is gone. */
    private int $offset = 0;

    public function append(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function contents(): string
    {
        return substr($this->buffer, $this->offset);
    }

    /**
     * Up to $maxBytes of what is still queued, for handing to one write.
     *
     * Callers ask for a slice rather than contents() so the copy is bounded
     * by the write size instead of by the backlog: a 16 MB backlog handed
     * to fwrite() in full is a 16 MB copy per attempt, and the kernel was
     * never going to take it all in one go anyway.
     */
    public function chunk(int $maxBytes): string
    {
        return substr($this->buffer, $this->offset, $maxBytes);
    }

    public function length(): int
    {
        return strlen($this->buffer) - $this->offset;
    }

    public function isEmpty(): bool
    {
        return $this->length() === 0;
    }

    /**
     * Marks the first $length queued bytes as written, e.g. after a partial
     * write.
     */
    public function consume(int $length): void
    {
        $this->offset += min(max($length, 0), $this->length());

        if ($this->offset === strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;

            return;
        }

        if ($this->offset >= self::COMPACTION_THRESHOLD_BYTES) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
    }
}
