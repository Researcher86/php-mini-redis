<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\WriteBuffer;
use PHPUnit\Framework\TestCase;

final class WriteBufferTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $buffer = new WriteBuffer();

        self::assertTrue($buffer->isEmpty());
        self::assertSame(0, $buffer->length());
        self::assertSame('', $buffer->contents());
    }

    public function testAppendQueuesBytes(): void
    {
        $buffer = new WriteBuffer();

        $buffer->append('+OK');
        $buffer->append("\r\n");

        self::assertSame("+OK\r\n", $buffer->contents());
        self::assertSame(5, $buffer->length());
        self::assertFalse($buffer->isEmpty());
    }

    public function testConsumeReturnsTheRemovedPrefixAfterAPartialWrite(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append("+OK\r\n");

        self::assertSame("+OK", $buffer->consume(3));
        self::assertSame("\r\n", $buffer->contents());
        self::assertFalse($buffer->isEmpty());

        self::assertSame("\r\n", $buffer->consume(2));

        self::assertTrue($buffer->isEmpty());
    }

    public function testClearEmptiesTheBuffer(): void
    {
        $buffer = new WriteBuffer();
        $buffer->append("+OK\r\n");

        $buffer->clear();

        self::assertTrue($buffer->isEmpty());
        self::assertSame('', $buffer->contents());
    }
}
