<?php

declare(strict_types=1);

namespace App\Protocol;

/**
 * Pulls every complete RESP value currently available out of a byte
 * buffer, leaving any trailing partial value untouched.
 *
 * A single TCP read may contain zero, one, or several values, and the
 * buffer keeps growing across reads until each value is fully there.
 */
final readonly class RespStreamReader
{
    public function __construct(private RespParser $parser = new RespParser())
    {
    }

    /**
     * @return array{0: list<RespValue>, 1: int} The parsed values, and how many bytes of $buffer they consumed in total.
     */
    public function readAll(string $buffer): array
    {
        $values = [];
        $offset = 0;

        while (true) {
            $parsed = $this->parser->parse(substr($buffer, $offset));

            if ($parsed === null) {
                break;
            }

            [$value, $consumed] = $parsed;
            $values[] = $value;
            $offset += $consumed;
        }

        return [$values, $offset];
    }
}
