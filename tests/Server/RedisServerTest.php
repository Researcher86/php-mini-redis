<?php

declare(strict_types=1);

namespace App\Tests\Server;

use App\Connection\ClientConnection;
use App\Connection\ConnectionState;
use App\EventLoop\SelectLoop;
use App\Server\RedisServer;
use App\Server\ServerConfig;
use App\Storage\InMemoryStore;
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

    public function testPartialCommandsAccumulateInTheReadBufferAcrossTicks(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);

            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nba");
            $loop->tick(1);
            self::assertSame("*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nba", $connection->readBuffer()->contents());
            self::assertSame(ConnectionState::Reading, $connection->state());

            fwrite($client, "r\r\n");
            $loop->tick(1);
            self::assertSame('', $connection->readBuffer()->contents());
            self::assertSame('bar', $server->store()->get('foo'));
            self::assertSame("+OK\r\n", fread($client, 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testExecutesCommandsSentByARealClientAndRepliesWithResp(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            fwrite($client, "*1\r\n\$4\r\nPING\r\n");
            $loop->tick(1);
            self::assertSame("+PONG\r\n", fread($client, 1024));

            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$4\r\nname\r\n\$5\r\nTanat\r\n");
            $loop->tick(1);
            self::assertSame("+OK\r\n", fread($client, 1024));

            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$4\r\nname\r\n");
            $loop->tick(1);
            self::assertSame("\$5\r\nTanat\r\n", fread($client, 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testProcessesMultiplePipelinedCommandsFromOneRead(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            fwrite($client, "*1\r\n\$4\r\nPING\r\n*1\r\n\$4\r\nPING\r\n");
            $loop->tick(1);

            self::assertSame("+PONG\r\n+PONG\r\n", fread($client, 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testMultipleCommandsPlusATrailingPartialOneAreHandledCorrectly(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            // Two complete PING commands plus the start of a third, all in
            // one read.
            fwrite($client, "*1\r\n\$4\r\nPING\r\n*1\r\n\$4\r\nPING\r\n*1\r\n\$4\r\nPI");
            $loop->tick(1);

            self::assertSame("+PONG\r\n+PONG\r\n", fread($client, 1024));
            self::assertSame("*1\r\n\$4\r\nPI", $connection->readBuffer()->contents());

            fwrite($client, "NG\r\n");
            $loop->tick(1);

            self::assertSame("+PONG\r\n", fread($client, 1024));
            self::assertSame('', $connection->readBuffer()->contents());

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testPipelinedCommandsAreAppliedInOrderWithoutWaitingForEachReply(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            // The client sends three commands back to back, without
            // reading a reply in between - and INCR/GET depend on the SET
            // (and each other) having already been applied in order.
            fwrite(
                $client,
                "*3\r\n\$3\r\nSET\r\n\$7\r\ncounter\r\n\$1\r\n1\r\n"
                . "*2\r\n\$4\r\nINCR\r\n\$7\r\ncounter\r\n"
                . "*2\r\n\$3\r\nGET\r\n\$7\r\ncounter\r\n",
            );
            $loop->tick(1);

            self::assertSame("+OK\r\n:2\r\n\$1\r\n2\r\n", fread($client, 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testAKeySetWithExExpiresAfterItsTtl(): void
    {
        $loop = new SelectLoop();
        $now = 1000.0;
        $store = new InMemoryStore(static function () use (&$now): float {
            return $now;
        });
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop, store: $store);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            fwrite($client, "*5\r\n\$3\r\nSET\r\n\$7\r\nsession\r\n\$3\r\nabc\r\n\$2\r\nEX\r\n\$2\r\n60\r\n");
            $loop->tick(1);
            self::assertSame("+OK\r\n", fread($client, 1024));

            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$7\r\nsession\r\n");
            $loop->tick(1);
            self::assertSame("\$3\r\nabc\r\n", fread($client, 1024));

            $now += 60;

            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$7\r\nsession\r\n");
            $loop->tick(1);
            self::assertSame("\$-1\r\n", fread($client, 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testExpiredKeysAreActivelyRemovedByTheSweepTimerWithoutBeingRead(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $loop = new SelectLoop($clock);
        $store = new InMemoryStore($clock);
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            store: $store,
            expirationSweepIntervalSeconds: 5.0,
        );

        try {
            $store->set('session', 'abc', ttlSeconds: 10);

            // Read the raw entries directly, instead of through has()/get(),
            // which would themselves lazily expire the key - the point here
            // is to prove the timer removes it without ever being read.
            $entries = new \ReflectionProperty($store, 'data');
            self::assertArrayHasKey('session', $entries->getValue($store));

            $now += 5;
            $loop->tick(0);
            self::assertArrayHasKey('session', $entries->getValue($store), 'Not due yet: the TTL has not elapsed.');

            $now += 5;
            $loop->tick(0);
            self::assertArrayNotHasKey('session', $entries->getValue($store));
        } finally {
            $server->stop();
        }
    }

    public function testIdleConnectionsAreClosedAfterTheTimeoutButActiveOnesAreNot(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            idleTimeoutSeconds: 0.3,
        );

        try {
            $idleClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($idleClient, $errstr);
            $idleConnection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $idleConnection);

            usleep(400_000);

            $activeClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($activeClient, $errstr);
            $activeConnection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $activeConnection);

            // Touch the active connection's activity right before the check,
            // well within the timeout, while the idle one has been silent
            // ever since it connected. Both the touch and the idle check
            // below happen within this single tick(), so PHPUnit's own
            // overhead between statements cannot affect the comparison.
            fwrite($activeClient, "*1\r\n\$4\r\nPING\r\n");
            $loop->tick(1);

            self::assertSame(ConnectionState::Closed, $idleConnection->state());
            self::assertNotSame(ConnectionState::Closed, $activeConnection->state());
            self::assertSame(1, $server->connectedClientCount());

            fclose($idleClient);
            fclose($activeClient);
        } finally {
            $server->stop();
        }
    }

    public function testAPartialWriteIsQueuedInTheWriteBufferInsteadOfBlocking(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            // A response this large will not fit in the socket's send
            // buffer in one fwrite() call, so completing it (a blocking
            // write would stall this test) requires queuing the remainder
            // and waiting for a writable event instead.
            $size = 8 * 1024 * 1024;
            $server->store()->set('big', str_repeat('x', $size));

            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$3\r\nbig\r\n");
            $loop->tick(1);

            self::assertGreaterThan(0, $connection->writeBuffer()->length(), 'The whole response should not fit in one write.');
            self::assertSame(ConnectionState::Writing, $connection->state());

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testMalformedInputDisconnectsOnlyThatClient(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            fwrite($client, "not resp at all\r\n");
            $loop->tick(1);

            self::assertSame(0, $server->connectedClientCount());
            self::assertSame(ConnectionState::Closed, $connection->state());

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testClientDisconnectIsDetectedAndCleanedUp(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);

            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            fclose($client);
            $loop->tick(1);

            self::assertSame(0, $server->connectedClientCount());
            self::assertSame(ConnectionState::Closed, $connection->state());
        } finally {
            $server->stop();
        }
    }
}
