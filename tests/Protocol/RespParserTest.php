<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Protocol;

use PhpMiniCache\Protocol\RespEncoder;
use PhpMiniCache\Protocol\RespParser;
use PhpMiniCache\Protocol\RespType;
use PhpMiniCache\Protocol\RespValue;
use PHPUnit\Framework\TestCase;

final class RespParserTest extends TestCase
{
    public function testParsesSimpleString(): void
    {
        $parser = new RespParser();

        [$value, $consumed] = $parser->parse("+OK\r\n");

        self::assertSame(RespType::SimpleString, $value->type);
        self::assertSame('OK', $value->value);
        self::assertSame(5, $consumed);
    }

    public function testParsesError(): void
    {
        $parser = new RespParser();

        [$value, $consumed] = $parser->parse("-ERR unknown command\r\n");

        self::assertSame(RespType::Error, $value->type);
        self::assertSame('ERR unknown command', $value->value);
        self::assertSame(22, $consumed);
    }

    public function testParsesInteger(): void
    {
        $parser = new RespParser();

        [$value, $consumed] = $parser->parse(":100\r\n");

        self::assertSame(RespType::Integer, $value->type);
        self::assertSame(100, $value->value);
        self::assertSame(6, $consumed);
    }

    public function testParsesBulkString(): void
    {
        $parser = new RespParser();

        [$value, $consumed] = $parser->parse("\$5\r\nhello\r\n");

        self::assertSame(RespType::BulkString, $value->type);
        self::assertSame('hello', $value->value);
        self::assertSame(11, $consumed);
    }

    public function testParsesNullBulkString(): void
    {
        $parser = new RespParser();

        [$value, $consumed] = $parser->parse("\$-1\r\n");

        self::assertSame(RespType::BulkString, $value->type);
        self::assertNull($value->value);
        self::assertSame(5, $consumed);
    }

    public function testParsesArrayOfBulkStrings(): void
    {
        $parser = new RespParser();

        [$value, $consumed] = $parser->parse("*2\r\n\$3\r\nGET\r\n\$3\r\nfoo\r\n");

        self::assertSame(RespType::Array, $value->type);
        self::assertCount(2, $value->value);
        self::assertSame('GET', $value->value[0]->value);
        self::assertSame('foo', $value->value[1]->value);
        self::assertSame(22, $consumed);
    }

    public function testParsesNullArray(): void
    {
        $parser = new RespParser();

        [$value, $consumed] = $parser->parse("*-1\r\n");

        self::assertSame(RespType::Array, $value->type);
        self::assertNull($value->value);
        self::assertSame(5, $consumed);
    }

    public function testReturnsNullWhenTheLineIsIncomplete(): void
    {
        $parser = new RespParser();

        self::assertNull($parser->parse('+OK'));
    }

    public function testReturnsNullWhenABulkStringBodyIsIncomplete(): void
    {
        $parser = new RespParser();

        self::assertNull($parser->parse("\$5\r\nhel"));
    }

    public function testReturnsNullWhenAnArrayElementIsIncomplete(): void
    {
        $parser = new RespParser();

        self::assertNull($parser->parse("*2\r\n\$3\r\nGET\r\n\$3\r\nfo"));
    }

    public function testConsumedBytesLeaveTheRemainderForTheNextParse(): void
    {
        $parser = new RespParser();

        [$first, $consumed] = $parser->parse("+OK\r\n+PONG\r\n");
        self::assertSame('OK', $first->value);

        [$second] = $parser->parse(substr("+OK\r\n+PONG\r\n", $consumed));
        self::assertSame('PONG', $second->value);
    }

    public function testRoundTripsThroughTheEncoder(): void
    {
        $parser = new RespParser();
        $encoder = new RespEncoder();

        $original = RespValue::array([
            RespValue::bulkString('SET'),
            RespValue::bulkString('foo'),
            RespValue::bulkString('bar'),
        ]);

        [$parsed, $consumed] = $parser->parse($encoder->encode($original));

        self::assertEquals($original, $parsed);
        self::assertSame(strlen($encoder->encode($original)), $consumed);
    }
}
