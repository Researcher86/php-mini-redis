<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\InMemoryStore;
use PHPUnit\Framework\TestCase;

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
        $now = 1000.0;
        $store = new InMemoryStore(static function () use (&$now): float {
            return $now;
        });

        $store->set('session', 'abc', ttlSeconds: 60);
        $now += 59;

        self::assertSame('abc', $store->get('session'));
        self::assertTrue($store->has('session'));
    }

    public function testAValueWithATtlIsGoneOnceItExpires(): void
    {
        $now = 1000.0;
        $store = new InMemoryStore(static function () use (&$now): float {
            return $now;
        });

        $store->set('session', 'abc', ttlSeconds: 60);
        $now += 60;

        self::assertNull($store->get('session'));
        self::assertFalse($store->has('session'));
    }

    public function testDeleteReturnsFalseForAnExpiredKey(): void
    {
        $now = 1000.0;
        $store = new InMemoryStore(static function () use (&$now): float {
            return $now;
        });

        $store->set('session', 'abc', ttlSeconds: 60);
        $now += 60;

        self::assertFalse($store->delete('session'));
    }

    public function testSetWithoutATtlNeverExpires(): void
    {
        $now = 1000.0;
        $store = new InMemoryStore(static function () use (&$now): float {
            return $now;
        });

        $store->set('name', 'Tanat');
        $now += 1_000_000;

        self::assertSame('Tanat', $store->get('name'));
    }

    public function testOverwritingAKeyReplacesItsPreviousTtl(): void
    {
        $now = 1000.0;
        $store = new InMemoryStore(static function () use (&$now): float {
            return $now;
        });

        $store->set('session', 'abc', ttlSeconds: 1);
        $store->set('session', 'def');
        $now += 60;

        self::assertSame('def', $store->get('session'));
    }
}
