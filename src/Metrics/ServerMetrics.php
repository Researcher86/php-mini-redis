<?php

declare(strict_types=1);

namespace PhpMiniCache\Metrics;

/**
 * Runtime counters for observability: connections, commands, bytes,
 * errors, expired keys. Nothing here changes server behavior - it only
 * records it.
 */
final class ServerMetrics
{
    private int $connectionsTotal = 0;
    private int $commandsProcessed = 0;

    /** @var array<string, int> */
    private array $commandsByType = [];

    private int $unknownCommands = 0;
    private int $bytesRead = 0;
    private int $bytesWritten = 0;
    private int $errors = 0;
    private int $keysExpired = 0;

    public function recordConnection(): void
    {
        $this->connectionsTotal++;
    }

    /**
     * Only ever called with the name of a command the server actually has a
     * handler for - see recordUnknownCommand() for why.
     */
    public function recordCommand(string $name): void
    {
        $this->commandsProcessed++;
        $this->commandsByType[$name] = ($this->commandsByType[$name] ?? 0) + 1;
    }

    /**
     * Counted, but never by name: the name of an unknown command is a string
     * a client chose, and keying a counter by it would let anyone grow this
     * map one entry per made-up name until the server runs out of memory.
     */
    public function recordUnknownCommand(): void
    {
        $this->commandsProcessed++;
        $this->unknownCommands++;
    }

    public function recordBytesRead(int $bytes): void
    {
        $this->bytesRead += $bytes;
    }

    public function recordBytesWritten(int $bytes): void
    {
        $this->bytesWritten += $bytes;
    }

    public function recordError(): void
    {
        $this->errors++;
    }

    public function recordExpiredKeys(int $count): void
    {
        $this->keysExpired += $count;
    }

    public function connectionsTotal(): int
    {
        return $this->connectionsTotal;
    }

    public function commandsProcessed(): int
    {
        return $this->commandsProcessed;
    }

    /** @return array<string, int> */
    public function commandsByType(): array
    {
        return $this->commandsByType;
    }

    public function unknownCommands(): int
    {
        return $this->unknownCommands;
    }

    public function bytesRead(): int
    {
        return $this->bytesRead;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function errors(): int
    {
        return $this->errors;
    }

    public function keysExpired(): int
    {
        return $this->keysExpired;
    }
}
