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
    public function __construct(
        private RespParser $parser = new RespParser(),
    ) {
    }

    /**
     * @return array{0: list<RespValue>, 1: int, 2: ProtocolException|null} The parsed values, how many bytes of $buffer they consumed in total, and - if the buffer ran out of valid RESP before its end - the failure. Values parsed before the failing byte are still returned, so the caller can apply what arrived in good order before acting on the error.
     */
    public function readAll(string $buffer): array
    {
        $values = [];
        $offset = 0;
        $error = null;

        try {
            while (true) {
                $parsed = $this->parser->parse($buffer, $offset);

                if ($parsed === null) {
                    break;
                }

                // The parser reports the absolute end position in $buffer
                // (it indexes straight into it), so carry that forward as
                // the next start offset rather than re-slicing the buffer.
                [$value, $offset] = $parsed;
                $values[] = $value;
            }
        } catch (ProtocolException $exception) {
            $error = $exception;
        }

        return [$values, $offset, $error];
    }
}
