<?php

declare(strict_types=1);

namespace App\Sdk;

/** Thrown when no reply arrives within the client's configured timeout. */
final class ReplyTimedOutException extends RedisClientException
{
}
