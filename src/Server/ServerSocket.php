<?php

declare(strict_types=1);

namespace PhpMiniCache\Server;

use RuntimeException;

/**
 * Wraps the raw listening socket: socket() + bind() + listen() + accept().
 */
final readonly class ServerSocket
{
    /** @var resource */
    private mixed $socket;

    public function __construct(
        ServerConfig $config,
    ) {
        $context = stream_context_create([
            'socket' => [
                'backlog' => $config->backlog,

                // Nagle's algorithm holds a small write back until the
                // previous one has been acknowledged, and a client that is
                // busy reading replies has no reason to acknowledge
                // promptly - so the second half of a pipeline's answers
                // could sit in the kernel for a delayed-ACK's worth of time
                // (~40 ms) before leaving. Real Redis disables it on every
                // connection for exactly this reason. Accepted sockets
                // inherit the listener's context, so setting it here covers
                // all of them.
                'tcp_nodelay' => true,
            ],
        ]);

        $socket = @stream_socket_server(
            sprintf('tcp://%s:%d', $config->host, $config->port),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('Failed to bind to %s:%d: %s (%d)', $config->host, $config->port, $errstr, $errno));
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
            throw new RuntimeException('Failed to read the local socket address.');
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
