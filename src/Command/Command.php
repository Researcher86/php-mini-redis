<?php

declare(strict_types=1);

namespace App\Command;

use App\Protocol\RespType;
use App\Protocol\RespValue;

/**
 * A parsed command, separate from the RESP value it came from: a name and
 * its arguments, e.g. ["SET", "foo", "bar"] becomes name "SET" with
 * arguments ["foo", "bar"].
 */
final readonly class Command
{
    private function __construct(
        public string $name,
        /** @var list<string> */
        public array $arguments,
    ) {
    }

    /**
     * Builds a Command from a parsed RESP array of bulk strings.
     */
    public static function fromRespValue(RespValue $value): self
    {
        if ($value->type !== RespType::Array || $value->value === null) {
            throw new CommandException('A command must be a non-null RESP array.');
        }

        if ($value->value === []) {
            throw new CommandException('A command must contain at least a name.');
        }

        $parts = array_map(self::bulkStringOf(...), $value->value);
        $name = array_shift($parts);

        return new self(strtoupper($name), $parts);
    }

    private static function bulkStringOf(RespValue $element): string
    {
        if ($element->type !== RespType::BulkString || $element->value === null) {
            throw new CommandException('Every command element must be a non-null bulk string.');
        }

        return $element->value;
    }
}
