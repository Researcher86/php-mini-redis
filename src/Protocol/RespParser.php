<?php

declare(strict_types=1);

namespace App\Protocol;

/**
 * Parses RESP values out of a byte buffer.
 *
 * A single TCP read is not guaranteed to contain a whole value, so parse()
 * returns null - rather than failing - whenever the buffer does not yet
 * hold enough bytes.
 */
final class RespParser
{
    /**
     * @return array{0: RespValue, 1: int}|null Null means more bytes are needed; the int is how many bytes were consumed.
     */
    public function parse(string $buffer): ?array
    {
        return $this->parseValue($buffer, 0);
    }

    /** @return array{0: RespValue, 1: int}|null */
    private function parseValue(string $buffer, int $pos): ?array
    {
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
            ':' => [RespValue::integer((int) $content), $pos],
            '$' => $this->parseBulkString($buffer, $pos, (int) $content),
            '*' => $this->parseArray($buffer, $pos, (int) $content),
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

    /** @return array{0: RespValue, 1: int}|null */
    private function parseBulkString(string $buffer, int $pos, int $length): ?array
    {
        if ($length === -1) {
            return [RespValue::bulkString(null), $pos];
        }

        $end = $pos + $length;

        if (!isset($buffer[$end + 1])) {
            return null;
        }

        return [RespValue::bulkString(substr($buffer, $pos, $length)), $end + 2];
    }

    /** @return array{0: RespValue, 1: int}|null */
    private function parseArray(string $buffer, int $pos, int $count): ?array
    {
        if ($count === -1) {
            return [RespValue::array(null), $pos];
        }

        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $parsed = $this->parseValue($buffer, $pos);

            if ($parsed === null) {
                return null;
            }

            [$items[], $pos] = $parsed;
        }

        return [RespValue::array($items), $pos];
    }
}
