<?php

declare(strict_types=1);

namespace App\Tests\Server;

use App\Connection\ClientConnection;
use App\Connection\ConnectionState;
use App\Server\RedisServer;
use App\Server\ServerConfig;
use PHPUnit\Framework\TestCase;

final class RedisServerTest extends TestCase
{
    public function testItAcceptsAConnectingClient(): void
    {
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0));

        try {
            self::assertSame(0, $server->connectedClientCount());

            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);

            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);
            self::assertSame(ConnectionState::Connected, $connection->state());
            self::assertSame(1, $server->connectedClientCount());

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testAcceptedSocketIsNonBlocking(): void
    {
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0));

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);

            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            $meta = stream_get_meta_data($connection->socket());
            self::assertFalse($meta['blocked']);

            // No data was sent: a blocking read would stall this test.
            self::assertSame('', fread($connection->socket(), 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testAcceptTimesOutWithoutAClient(): void
    {
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0));

        try {
            self::assertNull($server->acceptClient(0.1));
            self::assertSame(0, $server->connectedClientCount());
        } finally {
            $server->stop();
        }
    }

    public function testStopClosesTrackedConnections(): void
    {
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0));

        $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
        self::assertIsResource($client, $errstr);
        $connection = $server->acceptClient(5);
        self::assertInstanceOf(ClientConnection::class, $connection);

        $server->stop();

        self::assertSame(0, $server->connectedClientCount());
        self::assertSame(ConnectionState::Closed, $connection->state());
    }

    public function testRunAcceptsClientsThroughTheEventLoop(): void
    {
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0));

        $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
        self::assertIsResource($client, $errstr);

        $accepted = null;
        $server->run(function (ClientConnection $connection) use ($server, &$accepted): void {
            $accepted = $connection;
            $server->stop();
        });

        self::assertInstanceOf(ClientConnection::class, $accepted);
        self::assertSame(ConnectionState::Closed, $accepted->state());

        fclose($client);
    }
}
