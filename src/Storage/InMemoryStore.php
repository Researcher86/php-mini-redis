<?php

declare(strict_types=1);

namespace App\Storage;

final class InMemoryStore implements Store
{
    /** @var array<string, StoredValue> */
    private array $data = [];

    /** @var \Closure(): float */
    private \Closure $clock;

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $expiresAt = $ttlSeconds === null ? null : ($this->clock)() + $ttlSeconds;
        $this->data[$key] = new StoredValue($value, $expiresAt);
    }

    public function get(string $key): mixed
    {
        $entry = $this->entryOrNull($key);

        return $entry?->value;
    }

    public function has(string $key): bool
    {
        return $this->entryOrNull($key) !== null;
    }

    public function delete(string $key): bool
    {
        if (!$this->has($key)) {
            return false;
        }

        unset($this->data[$key]);

        return true;
    }

    public function sweepExpired(): int
    {
        $now = ($this->clock)();
        $removed = 0;

        foreach ($this->data as $key => $entry) {
            if ($entry->isExpired($now)) {
                unset($this->data[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Reads the entry for $key, lazily removing and treating it as absent
     * if its TTL has expired.
     */
    private function entryOrNull(string $key): ?StoredValue
    {
        $entry = $this->data[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry->isExpired(($this->clock)())) {
            unset($this->data[$key]);

            return null;
        }

        return $entry;
    }
}
