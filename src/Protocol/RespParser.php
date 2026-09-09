<?php

declare(strict_types=1);

namespace App\Protocol;

/**
 * Parses RESP values out of a byte buffer.
 *
 * A single TCP read is not guaranteed to contain a whole value, so parse()
 * returns null - rather than failing - whenever the buffer does not yet
 * hold enough bytes. Anything that is present but not valid RESP - a bad
 * type byte, a non-integer length, a bulk string whose trailing CRLF is
 * missing, a value past the configured protocol limits - is reported as a
 * ProtocolException: no amount of additional bytes can turn it into a
 * valid value.
 */
final readonly class RespParser
{
    /**
     * @param int $maxBulkStringBytes The largest single bulk string body
     *     a connection may legitimately carry. Shorter-than-limit values
     *     whose bytes have not all arrived yet keep returning null; a
     *     declared length above the limit is a protocol error up front,
     *     before the body is looked at, so a hostile header cannot force
     *     the server to scan for a multi-hundred-megabyte body.
     * @param int $maxArrayElements A hard ceiling on the number of
     *     elements in a single array. The command layer has its own, lower
     *     *soft* argument-count limit (replied with an error, connection
     *     kept); this one is the parse-time backstop that keeps a
     *     structurally absurd array from ever being built.
     * @param int $maxNestingDepth How deep arrays may nest. Unbounded
     *     recursion in parseArray() is a stack-exhaustion vector on its
     *     own, so divergence here is stopped while it is still shallow.
     */
    public function __construct(
        private readonly int $maxBulkStringBytes = 1024 * 1024,
        private readonly int $maxArrayElements = 1_000_000,
        private readonly int $maxNestingDepth = 32,
    ) {
    }

    /**
     * @return array{0: RespValue, 1: int}|null Null means more bytes are needed; the int is how many bytes were consumed. $offset allows parsing the next value out of the same buffer without re-slicing it.
     */
    public function parse(string $buffer, int $offset = 0): ?array
    {
        return $this->parseValue($buffer, $offset, 0);
    }

    /** @return array{0: RespValue, 1: int}|null */
    private function parseValue(string $buffer, int $pos, int $depth): ?array
    {
        if ($depth > $this->maxNestingDepth) {
            throw new ProtocolException('Maximum nesting depth reached.');
        }

        if (!isset($buffer[$pos])) {
            return null;
        }

        $type = $buffer[$pos];
        $line = $this->readLine($buffer, $pos + 1);

        if ($line === null) {
            return null;
        }

        [$content, $pos] = $line;

        return match ($type) {
            '+' => [RespValue::simpleString($content), $pos],
            '-' => [RespValue::error($content), $pos],
            ':' => [RespValue::integer($this->parseStrictInt($content, 'integer')), $pos],
            '$' => $this->parseBulkString($buffer, $pos, $this->parseLength($content, 'bulk string'), $depth),
            '*' => $this->parseArray($buffer, $pos, $this->parseLength($content, 'array'), $depth),
            default => throw new ProtocolException(sprintf('Unknown RESP type byte "%s".', $type)),
        };
    }

    /**
     * @return array{0: string, 1: int}|null The line content (without \r\n) and the position right after it.
     */
    private function readLine(string $buffer, int $pos): ?array
    {
        $crlf = strpos($buffer, "\r\n", $pos);

        if ($crlf === false) {
            return null;
        }

        return [substr($buffer, $pos, $crlf - $pos), $crlf + 2];
    }

    /**
     * Parses "-1" (null) or a non-negative count, rejecting anything else
     * on sight. A malformed or absurd length is a protocol error before
     * the body/elements are touched - a "$999999999999999999"\r\n header
     * must not make the parser scan for a body of that size.
     */
    private function parseLength(string $content, string $what): int
    {
        $length = $this->parseStrictInt($content, $what . ' length');

        if ($length === -1) {
            return -1;
        }

        if ($length < -1) {
            throw new ProtocolException(sprintf('Invalid %s length: %s.', $what, $content));
        }

        return $length;
    }

    /**
     * A strict integer parse. Casting is not enough: (int) "abc" is 0 and
     * (int) of an over-64-bit literal silently wraps, so both would turn
     * an invalid integer line into a value instead of a protocol error.
     */
    private function parseStrictInt(string $content, string $what): int
    {
        if (preg_match('/^-?(0|[1-9][0-9]*)$/', $content) !== 1) {
            throw new ProtocolException(sprintf('Invalid %s: "%s".', $what, $content));
        }

        $value = filter_var($content, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new ProtocolException(sprintf('Invalid %s: "%s".', $what, $content));
        }

        return $value;
    }

    /** @return array{0: RespValue, 1: int}|null */
    private function parseBulkString(string $buffer, int $pos, int $length, int $depth): ?array
    {
        if ($length === -1) {
            return [RespValue::bulkString(null), $pos];
        }

        if ($length > $this->maxBulkStringBytes) {
            throw new ProtocolException(sprintf('Bulk string length %d exceeds the configured maximum of %d.', $length, $this->maxBulkStringBytes));
        }

        $end = $pos + $length;

        // The two bytes of the trailing CRLF must be fully present before
        // the body is accepted; otherwise more data is needed. Once they
        // are in, they must actually be CRLF - "$5\r\nhelloXX" is not a
        // bulk string, regardless of how much more data follows it.
        if (!isset($buffer[$end + 1])) {
            return null;
        }

        if ($buffer[$end] !== "\r" || $buffer[$end + 1] !== "\n") {
            throw new ProtocolException('Bulk string body is not terminated by CRLF.');
        }

        return [RespValue::bulkString(substr($buffer, $pos, $length)), $end + 2];
    }

    /** @return array{0: RespValue, 1: int}|null */
    private function parseArray(string $buffer, int $pos, int $count, int $depth): ?array
    {
        if ($count === -1) {
            return [RespValue::array(null), $pos];
        }

        if ($count > $this->maxArrayElements) {
            throw new ProtocolException(sprintf('Array length %d exceeds the configured maximum of %d.', $count, $this->maxArrayElements));
        }

        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $parsed = $this->parseValue($buffer, $pos, $depth + 1);

            if ($parsed === null) {
                return null;
            }

            [$items[], $pos] = $parsed;
        }

        return [RespValue::array($items), $pos];
    }
}