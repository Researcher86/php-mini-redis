<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Connection\ClientConnection;

/**
 * A ClientConnection needs a real socket - this trait provides one cheaply
 * for tests that only need a connection to satisfy a type, not to
 * exercise actual I/O.
 */
trait CreatesTestConnections
{
    private function createConnection(): ClientConnection
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return new ClientConnection($pair[0]);
    }
}
