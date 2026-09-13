<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Protocol;

use PhpMiniCache\Protocol\ProtocolException;
use PhpMiniCache\Protocol\RespParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A table-driven pass over the RESP edge cases that a hand-written
 * parser is most likely to get wrong: truncations, malformed lengths,
 * missing/wrong terminator bytes, and input that is not RESP at all.
 *
 * Each row declares what the stream is, and whether the parser must
 * report "need more bytes" (null) or a protocol error. That split is the
 * parser's contract: anything *present* must either be a valid value or
 * fail loudly, never silently corrupt into garbage.
 */
final class RespParserFuzzTest extends TestCase
{
    #[DataProvider('needsMoreBytesProvider')]
    public function testNeedsMoreBytes(string $buffer): void
    {
        self::assertNull((new RespParser())->parse($buffer));
    }

    #[DataProvider('invalidProvider')]
    public function testMalformedInputRaisesAProtocolError(string $buffer): void
    {
        $this->expectException(ProtocolException::class);

        (new RespParser())->parse($buffer);
    }

    #[DataProvider('validProvider')]
    public function testValidInputStillParses(string $buffer): void
    {
        self::assertNotNull((new RespParser())->parse($buffer));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function needsMoreBytesProvider(): iterable
    {
        yield 'empty input' => [''];
        yield 'just a type byte' => ['+'];
        yield 'simple string without CRLF' => ['+OK'];
        yield 'integer line without CRLF' => [':100'];
        yield 'bulk header without CRLF' => ['$5'];
        yield 'bulk body truncated' => ["\$5\r\nhel"];
        yield 'bulk body truncated, CRLF cut in half' => ["\$5\r\nhello\r"];
        yield 'array header without CRLF' => ['*2'];
        yield 'array element truncated' => ["*2\r\n\$3\r\nGET\r\n\$3\r\nfo"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'unknown type byte' => ["!boom\r\n"];
        yield 'non-integer integer value' => [":abc\r\n"];
        yield 'blank integer value' => [":\r\n"];
        yield 'integer wrapping 64 bits' => [":9223372036854775808\r\n"];
        yield 'integer with internal junk' => [":1.5\r\n"];
        yield 'integer with leading whitespace' => [": 1\r\n"];
        yield 'bulk length not an integer' => ["\$abc\r\n"];
        yield 'blank bulk length' => ["\$\r\n"];
        yield 'bulk length below null' => ["\$-2\r\nhello\r\n"];
        yield 'array length below null' => ["*-2\r\n"];
        yield 'array length not an integer' => ["*lol\r\n"];
        yield 'blank array length' => ["*\r\n"];
        yield 'double minus array length' => ["*--1\r\n"];
        yield 'bulk body followed by wrong bytes' => ["\$5\r\nhelloXX"];
        yield 'bulk body followed by lone CR' => ["\$5\r\nhello\rX"];
        yield 'bulk body followed by lone LF' => ["\$5\r\nhello\nX"];
        yield 'oversized bulk declared up front' => ['$' . (1024 * 1024 + 1) . "\r\n"];
        yield 'oversized array declared up front' => ['*' . (1_000_000 + 1) . "\r\n"];
        yield 'deeply nested arrays past the limit' => [str_repeat("*1\r\n", 34) . "+ok\r\n"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validProvider(): iterable
    {
        yield 'null bulk' => ["\$-1\r\n"];
        yield 'empty bulk string' => ["\$0\r\n\r\n"];
        yield 'empty array' => ["*0\r\n"];
        yield 'null array' => ["*-1\r\n"];
        yield 'exactly-limit nesting' => [str_repeat("*1\r\n", 32) . "+ok\r\n"];
        yield 'nested array within the limit' => ["*2\r\n*1\r\n+inner\r\n+outer\r\n"];
    }
}