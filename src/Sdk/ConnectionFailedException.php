<?php

declare(strict_types=1);

namespace App\Sdk;

/** Thrown when RedisClient cannot open a connection to the server at all. */
final class ConnectionFailedException extends RedisClientException
{
}
