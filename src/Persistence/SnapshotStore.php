<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Storage\InMemoryStore;

/**
 * Saves an InMemoryStore's contents to a file and reloads them on startup.
 *
 * Optional, by design: the database is in-memory-only unless a snapshot
 * path is configured.
 */
final readonly class SnapshotStore
{
    public function __construct(
        private string $path,
    ) {
    }

    /**
     * Writes the store's current contents, replacing any previous
     * snapshot. Written to a temporary file first and renamed into place,
     * so a crash mid-write cannot leave a half-written, unreadable
     * snapshot behind. Throws if the temporary write fails, so a lost
     * snapshot is surfaced instead of silently gone.
     */
    public function save(InMemoryStore $store): void
    {
        $tmpPath = $this->path . '.tmp';

        $bytes = @file_put_contents($tmpPath, serialize($store->snapshot()));

        if ($bytes === false) {
            throw new \RuntimeException(sprintf('Failed to write snapshot to %s.', $tmpPath));
        }

        if (!@rename($tmpPath, $this->path)) {
            throw new \RuntimeException(sprintf('Failed to move snapshot into place at %s.', $this->path));
        }
    }

    /**
     * Loads the store's contents from a previously saved snapshot, if one
     * exists and is readable. A missing or unreadable file is not an
     * error - it just means there is nothing to restore yet.
     */
    public function load(InMemoryStore $store): void
    {
        if (!is_file($this->path)) {
            return;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false || $contents === '') {
            return;
        }

        $entries = @unserialize($contents, ['allowed_classes' => false]);

        if (!is_array($entries)) {
            return;
        }

        /** @var array<string, array{value: mixed, expiresAt: float|null}> $entries */
        $store->restore($entries);
    }
}
