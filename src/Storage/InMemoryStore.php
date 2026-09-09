<?php

declare(strict_types=1);

namespace App\Storage;

use App\Support\Clock;
use App\Support\SystemClock;

final class InMemoryStore implements Store
{
    /** @var array<string, StoredValue> */
    private array $data = [];

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $expiresAt = $ttlSeconds === null ? null : $this->clock->now() + $ttlSeconds;
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
        if ($this->entryOrNull($key) === null) {
            return false;
        }

        unset($this->data[$key]);

        return true;
    }

    public function sweepExpired(): int
    {
        $now = $this->clock->now();
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
     * A serializable copy of every entry, TTL included, for persistence.
     *
     * @return array<string, array{value: mixed, expiresAt: float|null}>
     */
    public function snapshot(): array
    {
        $entries = [];

        foreach ($this->data as $key => $entry) {
            $entries[$key] = ['value' => $entry->value, 'expiresAt' => $entry->expiresAt];
        }

        return $entries;
    }

    /**
     * Replaces the current contents with a previously taken snapshot().
     * Entries already expired by the time this runs are simply not
     * restored - the same lazy-expiration rule applies as everywhere else.
     *
     * @param array<string, array{value: mixed, expiresAt: float|null}> $entries
     */
    public function restore(array $entries): void
    {
        $this->data = [];

        foreach ($entries as $key => $entry) {
            $this->data[$key] = new StoredValue($entry['value'], $entry['expiresAt']);
        }
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

        if ($entry->isExpired($this->clock->now())) {
            unset($this->data[$key]);

            return null;
        }

        return $entry;
    }
}
