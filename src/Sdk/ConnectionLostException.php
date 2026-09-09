<?php

declare(strict_types=1);

namespace App\Sdk;

/**
 * The connection went away with a reply still owed. The server closes a
 * connection on a protocol error, past the client limit, or when a
 * subscriber falls too far behind - so this is a normal, expected outcome,
 * not necessarily a broken network.
 */
final class ConnectionLostException extends RedisClientException
{
}
