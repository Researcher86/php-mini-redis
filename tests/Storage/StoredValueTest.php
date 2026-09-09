<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\StoredValue;
use PHPUnit\Framework\TestCase;

final class StoredValueTest extends TestCase
{
    public function testAValueWithoutATtlNeverExpires(): void
    {
        $value = new StoredValue('Tanat');

        self::assertFalse($value->isExpired(PHP_FLOAT_MAX));
    }

    public function testAValueExpiresOnceNowReachesItsExpiryTimestamp(): void
    {
        $value = new StoredValue('Tanat', expiresAt: 100.0);

        self::assertFalse($value->isExpired(99.999));
        self::assertTrue($value->isExpired(100.0));
        self::assertTrue($value->isExpired(100.001));
    }
}
