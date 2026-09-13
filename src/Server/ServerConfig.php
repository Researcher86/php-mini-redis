<?php

declare(strict_types=1);

namespace PhpMiniCache\Server;

final readonly class ServerConfig
{
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 6380,
        public int $backlog = 128,
    ) {
    }
}
