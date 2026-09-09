<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Storage\InMemoryStore;
use Throwable;

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
final class ForkingSnapshotWorker
{
    /**
     * The child currently writing a snapshot, if any. Null once it has been
     * reaped - by this class, or by the server's own SIGCHLD handler.
     */
    private ?int $childPid = null;

    public function __construct(
        private readonly SnapshotStore $snapshots,
        /**
         * Where a child reports a failed write. Defaults to STDERR; a test
         * (or a caller with somewhere better to put it) can pass its own.
         *
         * @var resource|null
         */
        private readonly mixed $errorStream = null,
    ) {
    }

    /**
     * Returns false when a snapshot was already being written and this one
     * was skipped, the way real Redis refuses a `BGSAVE` while one is in
     * progress.
     *
     * Skipping matters because the interval between snapshots is configured,
     * while how long one takes is not: a store big enough to outlast its own
     * interval would otherwise fork a new child on every tick, each racing
     * the others to rename its own copy into place - so the last snapshot to
     * land is whichever child happened to finish last, not the newest.
     */
    public function save(InMemoryStore $store): bool
    {
        if (!function_exists('pcntl_fork')) {
            $this->snapshots->save($store);

            return true;
        }

        if ($this->isChildStillWriting()) {
            return false;
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->snapshots->save($store);

            return true;
        }

        if ($pid > 0) {
            // Parent: the child writes the snapshot; it is reaped through the
            // SIGCHLD handler the server installs, or by the check above.
            $this->childPid = $pid;

            return true;
        }

        // Child: write the snapshot and exit immediately, never returning to
        // the caller (which would keep running the parent's event loop). A
        // failed write exits non-zero rather than reporting the success it
        // did not have - the exit status is the only thing the child can
        // still say about it.
        try {
            $this->snapshots->save($store);
        } catch (Throwable $exception) {
            fwrite($this->errorStream ?? STDERR, sprintf("Snapshot failed: %s\n", $exception->getMessage()));

            exit(1);
        }

        exit(0);
    }

    /**
     * Reaps the previous child if it has finished, and reports whether it is
     * still going.
     *
     * pcntl_waitpid() rather than posix_kill($pid, 0): a child that has
     * exited but not yet been reaped is a zombie, which still answers to
     * signal 0 - so a server without a SIGCHLD handler would look like it
     * had a snapshot permanently in progress. -1 means it is already gone
     * (reaped by that handler, typically), which counts as finished too.
     */
    private function isChildStillWriting(): bool
    {
        if ($this->childPid === null) {
            return false;
        }

        if (pcntl_waitpid($this->childPid, $status, WNOHANG) === 0) {
            return true;
        }

        $this->childPid = null;

        return false;
    }
}
