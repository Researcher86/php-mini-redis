<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Persistence;

use PhpMiniCache\Persistence\SnapshotStore;
use PhpMiniCache\Storage\InMemoryStore;
use PhpMiniCache\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class SnapshotStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'mini-redis-snapshot-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        foreach (glob($this->path . '.*.tmp') ?: [] as $leftover) {
            @unlink($leftover);
        }
    }

    public function testLoadIntoAFreshStoreIsANoOpWhenNoSnapshotExists(): void
    {
        @unlink($this->path);
        $snapshots = new SnapshotStore($this->path);
        $store = new InMemoryStore();

        $snapshots->load($store);

        self::assertNull($store->get('anything'));
    }

    public function testSavedValuesAreRestoredIntoAnotherStore(): void
    {
        $snapshots = new SnapshotStore($this->path);
        $original = new InMemoryStore();
        $original->set('name', 'Tanat');
        $original->set('counter', '42');

        $snapshots->save($original);

        $restored = new InMemoryStore();
        $snapshots->load($restored);

        self::assertSame('Tanat', $restored->get('name'));
        self::assertSame('42', $restored->get('counter'));
    }

    public function testATtlSurvivesTheRoundTrip(): void
    {
        $clock = new FakeClock(1000.0);
        $snapshots = new SnapshotStore($this->path);
        $original = new InMemoryStore($clock);
        $original->set('session', 'abc', ttlSeconds: 60);

        $snapshots->save($original);

        $restored = new InMemoryStore($clock);
        $snapshots->load($restored);

        self::assertSame('abc', $restored->get('session'));

        $clock->advance(60);
        self::assertNull($restored->get('session'));
    }

    public function testSaveOverwritesAPreviousSnapshot(): void
    {
        $snapshots = new SnapshotStore($this->path);
        $store = new InMemoryStore();

        $store->set('name', 'first');
        $snapshots->save($store);

        $store->set('name', 'second');
        $snapshots->save($store);

        $restored = new InMemoryStore();
        $snapshots->load($restored);

        self::assertSame('second', $restored->get('name'));
    }

    public function testSavingIgnoresATemporaryFileLeftBehindByAnotherWriter(): void
    {
        $store = new InMemoryStore();
        $store->set('name', 'Tanat');

        // What another process writing a snapshot at the same time looks
        // like from here. Sharing one temporary path would mean renaming
        // that half-written file into place - the destination is supposed
        // to only ever hold a snapshot somebody finished writing.
        $foreignTmp = $this->path . '.999999.tmp';
        file_put_contents($foreignTmp, 'half a snapshot');

        try {
            (new SnapshotStore($this->path))->save($store);

            $reloaded = new InMemoryStore();
            (new SnapshotStore($this->path))->load($reloaded);

            self::assertSame('Tanat', $reloaded->get('name'));
            self::assertSame('half a snapshot', file_get_contents($foreignTmp));
        } finally {
            @unlink($foreignTmp);
        }
    }

    public function testACorruptSnapshotFileIsIgnoredInsteadOfCrashing(): void
    {
        file_put_contents($this->path, 'this is not a serialized snapshot');
        $snapshots = new SnapshotStore($this->path);
        $store = new InMemoryStore();

        $snapshots->load($store);

        self::assertNull($store->get('anything'));
    }
}
