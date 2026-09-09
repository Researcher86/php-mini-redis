<?php

declare(strict_types=1);

namespace App\Sdk;

/**
 * The server answered with a RESP error (`-ERR ...`) instead of a result.
 *
 * Without this, a command that failed would come back as an ordinary
 * string, and a caller that did not think to inspect every reply would
 * treat "the server refused" as data.
 */
final class CommandFailedException extends RedisClientException
{
    public function __construct(
        public readonly string $error,
    ) {
        parent::__construct($error);
    }
}
