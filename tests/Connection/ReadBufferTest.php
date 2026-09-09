<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\ReadBuffer;
use PHPUnit\Framework\TestCase;

final class ReadBufferTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $buffer = new ReadBuffer();

        self::assertTrue($buffer->isEmpty());
        self::assertSame(0, $buffer->length());
        self::assertSame('', $buffer->contents());
    }

    public function testAppendAccumulatesFragmentedWrites(): void
    {
        $buffer = new ReadBuffer();

        $buffer->append('SET f');
        $buffer->append('oo bar');

        self::assertSame('SET foo bar', $buffer->contents());
        self::assertSame(11, $buffer->length());
        self::assertFalse($buffer->isEmpty());
    }

    public function testConsumeRemovesAPrefix(): void
    {
        $buffer = new ReadBuffer();
        $buffer->append('SET foo bar');

        $chunk = $buffer->consume(4);

        self::assertSame('SET ', $chunk);
        self::assertSame('foo bar', $buffer->contents());
    }

    public function testClearEmptiesTheBuffer(): void
    {
        $buffer = new ReadBuffer();
        $buffer->append('SET foo bar');

        $buffer->clear();

        self::assertTrue($buffer->isEmpty());
        self::assertSame('', $buffer->contents());
    }
}
