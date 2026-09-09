<?php

declare(strict_types=1);

namespace App\Tests\Protocol;

use App\Protocol\RespEncoder;
use App\Protocol\RespValue;
use PHPUnit\Framework\TestCase;

final class RespEncoderTest extends TestCase
{
    public function testEncodesSimpleString(): void
    {
        $encoder = new RespEncoder();

        self::assertSame("+OK\r\n", $encoder->encode(RespValue::simpleString('OK')));
    }

    public function testEncodesError(): void
    {
        $encoder = new RespEncoder();

        self::assertSame("-ERR unknown command\r\n", $encoder->encode(RespValue::error('ERR unknown command')));
    }

    public function testEncodesInteger(): void
    {
        $encoder = new RespEncoder();

        self::assertSame(":100\r\n", $encoder->encode(RespValue::integer(100)));
    }

    public function testEncodesBulkString(): void
    {
        $encoder = new RespEncoder();

        self::assertSame("\$5\r\nhello\r\n", $encoder->encode(RespValue::bulkString('hello')));
    }

    public function testEncodesNullBulkStringAsNegativeOne(): void
    {
        $encoder = new RespEncoder();

        self::assertSame("\$-1\r\n", $encoder->encode(RespValue::bulkString(null)));
    }

    public function testEncodesArrayOfBulkStrings(): void
    {
        $encoder = new RespEncoder();

        $value = RespValue::array([
            RespValue::bulkString('GET'),
            RespValue::bulkString('foo'),
        ]);

        self::assertSame("*2\r\n\$3\r\nGET\r\n\$3\r\nfoo\r\n", $encoder->encode($value));
    }

    public function testEncodesNullArrayAsNegativeOne(): void
    {
        $encoder = new RespEncoder();

        self::assertSame("*-1\r\n", $encoder->encode(RespValue::array(null)));
    }
}
