<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * Finds a TCP port nothing is listening on, so a test can start a server
 * without picking a number and hoping - a fixed port collides with a
 * developer's own `make run-server`, with a parallel run, and with the
 * previous test if it has not let go yet.
 */
final class FreePort
{
    /**
     * Binds to port 0, which makes the kernel pick a free one, notes which
     * one it picked, and closes the socket again.
     *
     * The port is free when this returns and nothing reserves it, so
     * something else could in principle take it before the caller binds.
     * Nothing here is racing for ports in practice, and the alternative -
     * keeping the socket and passing it to whoever needs it - is what
     * binding before a fork() does instead.
     */
    public static function find(string $host = '127.0.0.1'): int
    {
        $socket = @stream_socket_server(sprintf('tcp://%s:0', $host), $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException(sprintf('Could not bind a port on %s: %s (%d)', $host, $errstr, $errno));
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($address === false) {
            throw new RuntimeException('Bound a port but could not read back which one.');
        }

        [, $port] = explode(':', $address);

        return (int) $port;
    }
}
