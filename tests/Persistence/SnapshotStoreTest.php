<?php

declare(strict_types=1);

namespace App\Tests\Persistence;

use App\Persistence\SnapshotStore;
use App\Storage\InMemoryStore;
use App\Tests\Support\FakeClock;
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
        @unlink($this->path . '.tmp');
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

    public function testACorruptSnapshotFileIsIgnoredInsteadOfCrashing(): void
    {
        file_put_contents($this->path, 'this is not a serialized snapshot');
        $snapshots = new SnapshotStore($this->path);
        $store = new InMemoryStore();

        $snapshots->load($store);

        self::assertNull($store->get('anything'));
    }
}
