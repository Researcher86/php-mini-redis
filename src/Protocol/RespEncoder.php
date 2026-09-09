<?php

declare(strict_types=1);

namespace App\Protocol;

final class RespEncoder
{
    public function encode(RespValue $value): string
    {
        return match ($value->type) {
            RespType::SimpleString => '+' . $value->value . "\r\n",
            RespType::Error => '-' . $value->value . "\r\n",
            RespType::Integer => ':' . $value->value . "\r\n",
            RespType::BulkString => $this->encodeBulkString($value->value),
            RespType::Array => $this->encodeArray($value->value),
        };
    }

    private function encodeBulkString(mixed $value): string
    {
        if ($value === null) {
            return '$-1' . "\r\n";
        }

        /** @var string $value RespValue::bulkString() only ever holds a string or null. */
        return '$' . strlen($value) . "\r\n" . $value . "\r\n";
    }

    private function encodeArray(mixed $items): string
    {
        if ($items === null) {
            return '*-1' . "\r\n";
        }

        /** @var list<RespValue> $items RespValue::array() only ever holds a list of values or null. */
        $encoded = '*' . count($items) . "\r\n";

        foreach ($items as $item) {
            $encoded .= $this->encode($item);
        }

        return $encoded;
    }
}
