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
}
