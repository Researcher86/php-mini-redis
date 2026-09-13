<?php

declare(strict_types=1);

namespace PhpMiniCache\Persistence;

use PhpMiniCache\Logging\Logger;
use PhpMiniCache\Logging\NullLogger;
use PhpMiniCache\Storage\InMemoryStore;
use Throwable;

/**
 * Writes snapshots in a forked child so the event loop is not blocked by
 * copying, serializing and writing the store to disk.
 *
 * The store is written as of the moment the child forks: copy-on-write
 * gives the child a consistent point-in-time view without the parent
 * copying anything up front, and the parent goes back to serving the loop
 * immediately. The child is reaped via SIGCHLD (installed by the server).
 *
 * "Free" only describes the parent's side. The child still builds the whole
 * snapshot array (`InMemoryStore::snapshot()`) and then serializes it, so
 * its own memory grows to roughly the size of the store again while it
 * works - which for a large store is the real cost of taking one, and the
 * reason a snapshot is not something to schedule every second.
 *
 * Falls back to a synchronous save when fork() fails - correct, just blocks
 * the loop for the duration.
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
        // How a child reports a failed write. Silent by default, because a
        // library class writing to somebody's stderr uninvited is not its
        // decision to make - bin/server.php hands down a ConsoleLogger.
        private readonly Logger $logger = new NullLogger(),
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
            $this->logger->error(sprintf('Snapshot failed: %s', $exception->getMessage()));

            exit(1);
        }

        exit(0);
    }

    /**
     * Blocks until the snapshot in progress, if any, has finished writing -
     * so a caller about to write one itself cannot be overtaken by a child
     * that started earlier and renames its older copy into place
     * afterwards.
     *
     * A child that is still going when the budget runs out is killed rather
     * than waited for: it is holding a point-in-time view that is now older
     * than what the caller is about to write, so letting it finish would
     * mean losing the newer snapshot to the older one - which is the
     * failure this method exists to prevent.
     */
    public function awaitCurrentSnapshot(float $timeoutSeconds = 5.0): void
    {
        if ($this->childPid === null) {
            return;
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while ($this->isChildStillWriting()) {
            if (microtime(true) >= $deadline) {
                posix_kill($this->childPid, SIGKILL);
                pcntl_waitpid($this->childPid, $status);
                $this->childPid = null;

                return;
            }

            usleep(1000);
        }
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
