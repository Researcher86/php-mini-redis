<?php

declare(strict_types=1);

namespace App\Protocol;

/**
 * One value of the Redis Serialization Protocol: a simple string, an
 * error, an integer, a bulk string (or null), or an array of values
 * (or null).
 */
final readonly class RespValue
{
    /**
     * @param ($type is RespType::Array ? list<self>|null : string|int|null) $value
     */
    private function __construct(
        public RespType $type,
        public string|int|array|null $value,
    ) {
    }

    public static function simpleString(string $value): self
    {
        return new self(RespType::SimpleString, $value);
    }

    public static function error(string $message): self
    {
        return new self(RespType::Error, $message);
    }

    public static function integer(int $value): self
    {
        return new self(RespType::Integer, $value);
    }

    public static function bulkString(?string $value): self
    {
        return new self(RespType::BulkString, $value);
    }

    /** @param list<self>|null $items */
    public static function array(?array $items): self
    {
        return new self(RespType::Array, $items);
    }
}
