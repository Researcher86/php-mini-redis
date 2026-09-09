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
        @unlink($this->path);
        @unlink($this->path . '.tmp');
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
