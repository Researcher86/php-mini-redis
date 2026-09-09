<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Storage\InMemoryStore;

/**
 * Writes snapshots in a forked child so the event loop is not blocked by
 * copying, serializing and writing the store to disk.
 *
 * The store is written as of the moment the child forks: PHP's copy-on-write
 * means the child's view of the store is a consistent point-in-time snapshot
 * taken for free, with no upfront copy in the parent. The parent keeps
 * serving the loop and reaps the child via SIGCHLD (installed by the
 * server).
 *
 * Falls back to a synchronous save when pcntl is unavailable or fork fails -
 * correct, just blocks the loop for the duration.
 */
final readonly class ForkingSnapshotWorker
{
    public function __construct(
        private SnapshotStore $snapshots,
    ) {
    }

    public function save(InMemoryStore $store): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->snapshots->save($store);

            return;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->snapshots->save($store);

            return;
        }

        if ($pid > 0) {
            // Parent: the child writes the snapshot; it is reaped through the
            // SIGCHLD handler the server installs. Nothing to do here.
            return;
        }

        // Child: write the snapshot and exit immediately, never returning to
        // the caller (which would keep running the parent's event loop).
        try {
            $this->snapshots->save($store);
        } finally {
            exit(0);
        }
    }
}
