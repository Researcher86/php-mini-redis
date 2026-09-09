<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\InMemoryStore;
use App\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class InMemoryStoreTest extends TestCase
{
    public function testGetReturnsNullForAMissingKey(): void
    {
        $store = new InMemoryStore();

        self::assertNull($store->get('missing'));
        self::assertFalse($store->has('missing'));
    }

    public function testSetAndGetRoundTrip(): void
    {
        $store = new InMemoryStore();

        $store->set('name', 'Tanat');

        self::assertSame('Tanat', $store->get('name'));
        self::assertTrue($store->has('name'));
    }

    public function testSetOverwritesAnExistingValue(): void
    {
        $store = new InMemoryStore();

        $store->set('counter', 1);
        $store->set('counter', 2);

        self::assertSame(2, $store->get('counter'));
    }

    public function testDeleteRemovesAnExistingKeyAndReturnsTrue(): void
    {
        $store = new InMemoryStore();
        $store->set('name', 'Tanat');

        self::assertTrue($store->delete('name'));
        self::assertFalse($store->has('name'));
        self::assertNull($store->get('name'));
    }

    public function testDeleteReturnsFalseForAMissingKey(): void
    {
        $store = new InMemoryStore();

        self::assertFalse($store->delete('missing'));
    }

    public function testAValueWithATtlIsAvailableBeforeItExpires(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('session', 'abc', ttlSeconds: 60);
        $clock->advance(59);

        self::assertSame('abc', $store->get('session'));
        self::assertTrue($store->has('session'));
    }

    public function testAValueWithATtlIsGoneOnceItExpires(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('session', 'abc', ttlSeconds: 60);
        $clock->advance(60);

        self::assertNull($store->get('session'));
        self::assertFalse($store->has('session'));
    }

    public function testDeleteReturnsFalseForAnExpiredKey(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('session', 'abc', ttlSeconds: 60);
        $clock->advance(60);

        self::assertFalse($store->delete('session'));
    }

    public function testSetWithoutATtlNeverExpires(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('name', 'Tanat');
        $clock->advance(1_000_000);

        self::assertSame('Tanat', $store->get('name'));
    }

    public function testOverwritingAKeyReplacesItsPreviousTtl(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('session', 'abc', ttlSeconds: 1);
        $store->set('session', 'def');
        $clock->advance(60);

        self::assertSame('def', $store->get('session'));
    }

    public function testSweepExpiredRemovesOnlyExpiredEntriesAndReportsHowMany(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('short', 'a', ttlSeconds: 10);
        $store->set('long', 'b', ttlSeconds: 100);
        $store->set('forever', 'c');
        $clock->advance(10);

        self::assertSame(1, $store->sweepExpired());
        self::assertFalse($store->has('short'));
        self::assertTrue($store->has('long'));
        self::assertTrue($store->has('forever'));
        self::assertSame(0, $store->sweepExpired());
    }

    public function testSnapshotAndRestoreRoundTripValuesAndTtls(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);
        $store->set('name', 'Tanat');
        $store->set('session', 'abc', ttlSeconds: 60);

        $snapshot = $store->snapshot();

        $restored = new InMemoryStore($clock);
        $restored->restore($snapshot);

        self::assertSame('Tanat', $restored->get('name'));
        self::assertSame('abc', $restored->get('session'));

        $clock->advance(60);
        self::assertNull($restored->get('session'));
    }

    public function testRestoreReplacesWhateverWasThereBefore(): void
    {
        $store = new InMemoryStore();
        $store->set('stale', 'value');

        $store->restore(['fresh' => ['value' => 'value', 'expiresAt' => null]]);

        self::assertFalse($store->has('stale'));
        self::assertSame('value', $store->get('fresh'));
    }

    public function testOverwritingAnExpiringKeyDoesNotLetItsOldTtlKillTheNewValue(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('session', 'first', ttlSeconds: 10);
        $store->set('session', 'second'); // overwrite, no TTL - the old heap entry must go stale
        $clock->advance(10);

        self::assertSame('second', $store->get('session'));
        self::assertSame(0, $store->sweepExpired());
        self::assertSame('second', $store->get('session'));
    }

    public function testSweepSkipsAStaleHeapEntryAfterTheKeyWasDeleted(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->set('gone', 'value', ttlSeconds: 10);
        $store->delete('gone');
        $clock->advance(10);

        self::assertSame(0, $store->sweepExpired());
    }

    public function testKeysThatCameAndWentLeaveNoPerKeyBookkeepingBehind(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        for ($i = 0; $i < 100; $i++) {
            $store->set('expiring:' . $i, 'value', ttlSeconds: 1);
            $store->set('deleted:' . $i, 'value', ttlSeconds: 1);
            $store->delete('deleted:' . $i);
        }

        $store->set('read:0', 'value', ttlSeconds: 1);
        $clock->advance(1);
        self::assertNull($store->get('read:0')); // lazily expired on access
        self::assertSame(100, $store->sweepExpired());

        // A cache churning through short-lived keys must not accumulate one
        // entry per key it has ever held - whichever of the three ways a key
        // can leave took it away.
        $versions = new ReflectionProperty($store, 'versions');
        self::assertSame([], $versions->getValue($store));
    }

    public function testAKeyWrittenAgainAfterDeletionSurvivesItsPreviousTtl(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        // The first life queues a heap entry due at 1010. The second one is
        // written at the same key with a longer TTL, so the entry that comes
        // due first belongs to a value that no longer exists.
        $store->set('session', 'first', ttlSeconds: 10);
        $store->delete('session');
        $store->set('session', 'second', ttlSeconds: 60);

        $clock->advance(10);

        self::assertSame(0, $store->sweepExpired());
        self::assertSame('second', $store->get('session'));
    }

    public function testSetKeepingTtlRewritesTheValueOnTheSameExpiration(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);
        $store->set('session', 'first', ttlSeconds: 10);

        self::assertTrue($store->setKeepingTtl('session', 'second'));

        $clock->advance(9);
        self::assertSame('second', $store->get('session'));

        $clock->advance(1);
        self::assertNull($store->get('session'));
        self::assertSame(0, $store->sweepExpired()); // already gone lazily
    }

    public function testSetKeepingTtlReportsAKeyThatIsNotThereAndWritesNothing(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);
        $store->set('gone', 'value', ttlSeconds: 10);
        $clock->advance(10);

        self::assertFalse($store->setKeepingTtl('gone', 'value'));
        self::assertFalse($store->setKeepingTtl('never-existed', 'value'));
        self::assertFalse($store->has('gone'));
        self::assertFalse($store->has('never-existed'));
    }

    public function testRestoreDropsEntriesAlreadyExpiredAtRestoreTime(): void
    {
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);

        $store->restore([
            'dead' => ['value' => 'x', 'expiresAt' => 1000.0],
            'alive' => ['value' => 'y', 'expiresAt' => 2000.0],
        ]);

        self::assertFalse($store->has('dead'));
        self::assertSame('y', $store->get('alive'));
        self::assertSame(0, $store->sweepExpired());
    }
}
