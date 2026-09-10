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
     *
     * That shape is the only one accepted: real clients send commands as an
     * array of bulk strings, and anything else - a bare simple string, an
     * integer, a nested array - is a client bug rather than a command this
     * server has not implemented yet, so it is refused here instead of
     * being carried further as a name nobody can dispatch.
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

        // Uppercased once, here, so every later comparison - the dispatcher's
        // lookup, the transaction layer's MULTI/EXEC/DISCARD check, the
        // per-command metrics - is a plain equality against one spelling.
        // Arguments keep their case: they are data, not names.
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
