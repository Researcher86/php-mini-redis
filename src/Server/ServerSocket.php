<?php

declare(strict_types=1);

namespace App\Server;

/**
 * Wraps the raw listening socket: socket() + bind() + listen() + accept().
 */
final class ServerSocket
{
    /** @var resource */
    private $socket;

    public function __construct(ServerConfig $config)
    {
        $context = stream_context_create([
            'socket' => ['backlog' => $config->backlog],
        ]);

        $socket = @stream_socket_server(
            sprintf('tcp://%s:%d', $config->host, $config->port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($socket === false) {
            throw new \RuntimeException(sprintf('Failed to bind to %s:%d: %s (%d)', $config->host, $config->port, $errstr, $errno));
        }

        $this->socket = $socket;
    }

    /** @return resource */
    public function resource(): mixed
    {
        return $this->socket;
    }

    public function localAddress(): string
    {
        $address = stream_socket_get_name($this->socket, false);

        if ($address === false) {
            throw new \RuntimeException('Failed to read the local socket address.');
        }

        return $address;
    }

    /**
     * Blocks until a client connects, or until $timeoutSeconds elapses.
     *
     * The accepted socket is switched to non-blocking mode: reads and
     * writes on it must never stall the event loop while other clients
     * are waiting to be served.
     *
     * @return resource|false
     */
    public function accept(?float $timeoutSeconds = null)
    {
        // A timeout is an expected outcome here, not an error worth a warning.
        $connection = @stream_socket_accept($this->socket, $timeoutSeconds ?? -1);

        if ($connection !== false) {
            stream_set_blocking($connection, false);
        }

        return $connection;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }
}
