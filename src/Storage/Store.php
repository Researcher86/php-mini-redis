<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The database core: stores, reads, updates and deletes keyed values.
 *
 * A Store must not know about TCP, RESP or connections - only about data.
 */
interface Store
{
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): void;

    /**
     * Replaces an existing key's value while leaving its expiration exactly
     * where it was. A command that only rewrites a value - INCR - must not
     * turn an expiring key into a permanent one as a side effect.
     *
     * @return bool True if the key was there to be rewritten; false leaves
     *     the store untouched, so the caller can write it as a new key.
     */
    public function setKeepingTtl(string $key, mixed $value): bool;

    public function get(string $key): mixed;

    public function has(string $key): bool;

    /**
     * @return bool True if the key existed and was removed.
     */
    public function delete(string $key): bool;

    /**
     * Removes every expired entry right away, instead of waiting for it to
     * be noticed on the next access. Returns how many were removed.
     */
    public function sweepExpired(): int;
}
