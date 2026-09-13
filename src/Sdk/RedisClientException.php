<?php

declare(strict_types=1);

namespace PhpMiniCache\Sdk;

use RuntimeException;

/**
 * The base every RedisClient failure extends, so a caller that only wants
 * "talking to the server did not work out" can catch one type instead of
 * four - and one that cares which is which still can.
 */
abstract class RedisClientException extends RuntimeException
{
}
