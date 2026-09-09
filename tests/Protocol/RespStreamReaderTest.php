<?php

declare(strict_types=1);

namespace App\Tests\Protocol;

use App\Protocol\RespStreamReader;
use PHPUnit\Framework\TestCase;

final class RespStreamReaderTest extends TestCase
{
    public function testReturnsNothingForAnIncompleteValue(): void
    {
        $reader = new RespStreamReader();

        [$values, $consumed] = $reader->readAll("*2\r\n\$3\r\nGET\r\n\$3\r\nfo");

        self::assertSame([], $values);
        self::assertSame(0, $consumed);
    }

    public function testExtractsAValueOnceItArrivesAcrossMultipleReads(): void
    {
        $reader = new RespStreamReader();

        $buffer = "*2\r\n\$3\r\nGET\r\n\$3\r\nfo";
        [$values, $consumed] = $reader->readAll($buffer);
        self::assertSame([], $values);

        $buffer .= "o\r\n";
        [$values, $consumed] = $reader->readAll($buffer);

        self::assertCount(1, $values);
        self::assertSame('GET', $values[0]->value[0]->value);
        self::assertSame('foo', $values[0]->value[1]->value);
        self::assertSame(strlen($buffer), $consumed);
    }

    public function testExtractsMultipleValuesFromOneBuffer(): void
    {
        $reader = new RespStreamReader();

        [$values, $consumed] = $reader->readAll("+PING\r\n+PONG\r\n");

        self::assertCount(2, $values);
        self::assertSame('PING', $values[0]->value);
        self::assertSame('PONG', $values[1]->value);
        self::assertSame(14, $consumed);
    }

    public function testLeavesATrailingPartialValueUnconsumed(): void
    {
        $reader = new RespStreamReader();

        [$values, $consumed] = $reader->readAll("+PING\r\n+PON");

        self::assertCount(1, $values);
        self::assertSame('PING', $values[0]->value);
        self::assertSame(7, $consumed);
    }

    public function testKeepsValuesParsedBeforeAMalformedTailAndReportsTheError(): void
    {
        $reader = new RespStreamReader();

        [$values, $consumed, $error] = $reader->readAll("+PING\r\nbad\r\n");

        self::assertCount(1, $values);
        self::assertSame('PING', $values[0]->value);
        self::assertSame(7, $consumed);
        self::assertInstanceOf(\App\Protocol\ProtocolException::class, $error);
    }

    public function testReportsNoErrorForAWellFormedBuffer(): void
    {
        $reader = new RespStreamReader();

        [$values, $consumed, $error] = $reader->readAll("+PING\r\n");

        self::assertCount(1, $values);
        self::assertNull($error);
    }
}
