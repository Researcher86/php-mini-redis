<?php

declare(strict_types=1);

namespace PhpMiniCache\Sdk;

/** Thrown when no reply arrives within the client's configured timeout. */
final class ReplyTimedOutException extends RedisClientException
{
}
