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
    public function set(string $key, mixed $value): void;

    public function get(string $key): mixed;

    public function has(string $key): bool;

    /**
     * @return bool True if the key existed and was removed.
     */
    public function delete(string $key): bool;
}
