<?php

declare(strict_types=1);

namespace App\Sdk;

/** One message delivered to a subscribed connection. */
final readonly class PubSubMessage
{
    public function __construct(
        public string $channel,
        public string $payload,
    ) {
    }
}
