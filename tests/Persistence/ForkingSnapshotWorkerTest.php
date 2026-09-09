<?php

declare(strict_types=1);

namespace App\Tests\Persistence;

use App\Persistence\ForkingSnapshotWorker;
use App\Persistence\SnapshotStore;
use App\Storage\InMemoryStore;
use PHPUnit\Framework\TestCase;

final class ForkingSnapshotWorkerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'mini-redis-fork-snapshot-');
    }

    protected function tearDown(): void
    {
        // Reap the snapshot children before leaving, so a test run does not
        // trail zombies behind it.
        while (pcntl_waitpid(-1, $status) > 0) {
        }

        @unlink($this->path);

        foreach (glob($this->path . '.*.tmp') ?: [] as $leftover) {
            @unlink($leftover);
        }
    }

    public function testTheForkedChildWritesALoadableSnapshot(): void
    {
        $worker = new ForkingSnapshotWorker(new SnapshotStore($this->path));
        $store = new InMemoryStore();
        $store->set('name', 'Tanat');

        $worker->save($store);

        // The write happens in a forked child (Phase 35), so it may not be
        // on disk the instant save() returns; wait for it to land.
        $this->waitForSnapshot();

        $reloaded = new InMemoryStore();
        (new SnapshotStore($this->path))->load($reloaded);

        self::assertSame('Tanat', $reloaded->get('name'));
    }

    public function testASecondSaveIsSkippedWhileTheFirstChildIsStillWriting(): void
    {
        $worker = new ForkingSnapshotWorker(new SnapshotStore($this->path));

        // Big enough that writing it out lasts well beyond the microseconds
        // between the two calls below - the shape of a store whose snapshot
        // outlives the interval it is taken on.
        $store = new InMemoryStore();

        for ($i = 0; $i < 5000; $i++) {
            $store->set('key:' . $i, str_repeat('x', 1024));
        }

        self::assertTrue($worker->save($store));
        self::assertFalse($worker->save($store), 'A snapshot was already in progress.');

        $this->waitForSnapshot();

        // Once the child is done, the next snapshot goes ahead as usual. It
        // renames the file into place just before exiting, so the file
        // landing is not quite proof the process is gone yet.
        for ($i = 0; $i < 200 && !$worker->save($store); $i++) {
            usleep(10_000);
        }

        self::assertLessThan(200, $i, 'A finished child should not block the next snapshot.');
    }

    private function waitForSnapshot(): void
    {
        for ($i = 0; $i < 200; $i++) {
            clearstatcache(true, $this->path);
            if ((filesize($this->path) ?? 0) > 0) {
                return;
            }
            usleep(10_000);
        }

        self::fail('The forked snapshot worker should have written the file.');
    }
}
