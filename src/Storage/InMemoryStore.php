<?php

declare(strict_types=1);

namespace App\Storage;

use App\Support\Clock;
use App\Support\SystemClock;

final class InMemoryStore implements Store
{
    /** @var array<string, StoredValue> */
    private array $data = [];

    /**
     * Expiring keys ordered by when they are due, so the sweep can pop only
     * what is actually due instead of walking every entry. Each heap element
     * is [expiresAt, version, key].
     *
     * @var \SplMinHeap<array{0: float, 1: int, 2: string}>
     */
    private \SplMinHeap $expirations;

    /**
     * Per-key version, bumped on every set/delete. A heap entry is only
     * applied when its recorded version still matches - so a key overwritten
     * (or deleted) since a heap push is never expired by that stale entry.
     *
     * @var array<string, int>
     */
    private array $versions = [];

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->expirations = new \SplMinHeap();
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $expiresAt = $ttlSeconds === null ? null : $this->clock->now() + $ttlSeconds;
        $this->data[$key] = new StoredValue($value, $expiresAt);

        $version = ($this->versions[$key] ?? 0) + 1;
        $this->versions[$key] = $version;

        if ($expiresAt !== null) {
            $this->expirations->insert([$expiresAt, $version, $key]);
        }
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

        $this->versions[$key] = ($this->versions[$key] ?? 0) + 1;
        unset($this->data[$key]);

        return true;
    }

    public function sweepExpired(): int
    {
        $now = $this->clock->now();
        $removed = 0;

        while (!$this->expirations->isEmpty()) {
            [$expiresAt, $version, $key] = $this->expirations->top();

            if ($expiresAt > $now) {
                break;
            }

            // The earliest due entry is past its time. Pop it; if the key is
            // still at the version it had when queued, it is genuinely this
            // key's current TTL and the entry is expired for real.
            $this->expirations->extract();

            $entry = $this->data[$key] ?? null;

            if (
                $entry !== null
                && $entry->expiresAt !== null
                && $entry->expiresAt === $expiresAt
                && ($this->versions[$key] ?? 0) === $version
            ) {
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
        $this->versions = [];
        $this->expirations = new \SplMinHeap();

        foreach ($entries as $key => $entry) {
            $expiresAt = $entry['expiresAt'];

            if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
                continue;
            }

            $this->data[$key] = new StoredValue($entry['value'], $expiresAt);

            if ($expiresAt !== null) {
                $version = ($this->versions[$key] ?? 0) + 1;
                $this->versions[$key] = $version;
                $this->expirations->insert([$expiresAt, $version, $key]);
            }
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
