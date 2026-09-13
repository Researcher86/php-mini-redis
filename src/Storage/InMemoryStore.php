<?php

declare(strict_types=1);

namespace PhpMiniCache\Storage;

use PhpMiniCache\Support\Clock;
use PhpMiniCache\Support\SystemClock;
use SplMinHeap;

final class InMemoryStore implements Store
{
    /** @var array<string, StoredValue> */
    private array $data = [];

    /**
     * Expiring keys ordered by when they are due, so the sweep can pop only
     * what is actually due instead of walking every entry. Each heap element
     * is [expiresAt, key].
     *
     * Entries are never removed here when a key is overwritten or deleted -
     * only when they come due. A stale one costs nothing: the sweep applies
     * an entry only if the key's *current* expiry is still the one the entry
     * was queued with, which is the whole test of whether it is stale.
     *
     * @var SplMinHeap<array{0: float, 1: string}>
     */
    private SplMinHeap $expirations;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->expirations = new SplMinHeap();
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void
    {
        $expiresAt = $ttlSeconds === null ? null : $this->clock->now() + $ttlSeconds;
        $this->data[$key] = new StoredValue($value, $expiresAt);

        if ($expiresAt !== null) {
            $this->expirations->insert([$expiresAt, $key]);
        }
    }

    public function setKeepingTtl(string $key, mixed $value): bool
    {
        $entry = $this->entryOrNull($key);

        if ($entry === null) {
            return false;
        }

        // The key's heap entry stays as it is: it is keyed on the
        // expiration, and the expiration is precisely what does not change
        // here.
        $this->data[$key] = new StoredValue($value, $entry->expiresAt);

        return true;
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

        while (!$this->expirations->isEmpty()) {
            [$expiresAt, $key] = $this->expirations->top();

            // The top of the heap is the earliest due entry, so once it is
            // in the future every entry behind it is too - that is what
            // makes this O(what expired) instead of O(everything stored).
            if ($expiresAt > $now) {
                break;
            }

            $this->expirations->extract();

            $entry = $this->data[$key] ?? null;

            // The key may have been deleted, overwritten with a new TTL, or
            // overwritten without one since this entry was queued, leaving
            // the entry stale. Comparing the entry's due time against the
            // key's current one settles all three: they match only when the
            // key is still living by exactly the expiry that just came due.
            if ($entry !== null && $entry->expiresAt === $expiresAt) {
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
        $this->expirations = new SplMinHeap();

        foreach ($entries as $key => $entry) {
            $expiresAt = $entry['expiresAt'];

            if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
                continue;
            }

            $this->data[$key] = new StoredValue($entry['value'], $expiresAt);

            if ($expiresAt !== null) {
                $this->expirations->insert([$expiresAt, $key]);
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
