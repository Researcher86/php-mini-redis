<?php

declare(strict_types=1);

namespace App\Tests\Server;

use App\Connection\ClientConnection;
use App\Connection\ConnectionState;
use App\EventLoop\SelectLoop;
use App\Server\RedisServer;
use App\Server\ServerConfig;
use App\Storage\InMemoryStore;
use App\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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
        $clock = new FakeClock(1000.0);
        $store = new InMemoryStore($clock);
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

            $clock->advance(60);

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
        $clock = new FakeClock(1000.0);
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
            $entries = new ReflectionProperty($store, 'data');
            self::assertArrayHasKey('session', $entries->getValue($store));

            $clock->advance(5);
            $loop->tick(0);
            self::assertArrayHasKey('session', $entries->getValue($store), 'Not due yet: the TTL has not elapsed.');

            $clock->advance(5);
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

    public function testASubscriberReceivesAPublishedMessage(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $subscriberClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($subscriberClient, $errstr);
            $server->acceptClient(5);

            fwrite($subscriberClient, "*2\r\n\$9\r\nSUBSCRIBE\r\n\$4\r\nnews\r\n");
            $loop->tick(1);
            self::assertSame("*3\r\n\$9\r\nsubscribe\r\n\$4\r\nnews\r\n:1\r\n", fread($subscriberClient, 1024));

            $publisherClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($publisherClient, $errstr);
            $server->acceptClient(5);

            fwrite($publisherClient, "*3\r\n\$7\r\nPUBLISH\r\n\$4\r\nnews\r\n\$5\r\nhello\r\n");
            $loop->tick(1);

            self::assertSame(':1' . "\r\n", fread($publisherClient, 1024));
            self::assertSame("*3\r\n\$7\r\nmessage\r\n\$4\r\nnews\r\n\$5\r\nhello\r\n", fread($subscriberClient, 1024));

            fclose($subscriberClient);
            fclose($publisherClient);
        } finally {
            $server->stop();
        }
    }

    public function testDisconnectingASubscriberRemovesItFromItsChannels(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $subscriberClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($subscriberClient, $errstr);
            $server->acceptClient(5);

            fwrite($subscriberClient, "*2\r\n\$9\r\nSUBSCRIBE\r\n\$4\r\nnews\r\n");
            $loop->tick(1);
            fread($subscriberClient, 1024);

            fclose($subscriberClient);
            $loop->tick(1);

            $publisherClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($publisherClient, $errstr);
            $server->acceptClient(5);

            fwrite($publisherClient, "*3\r\n\$7\r\nPUBLISH\r\n\$4\r\nnews\r\n\$5\r\nhello\r\n");
            $loop->tick(1);

            self::assertSame(':0' . "\r\n", fread($publisherClient, 1024));

            fclose($publisherClient);
        } finally {
            $server->stop();
        }
    }

    public function testPublishingToAConnectionThatIsAlreadyClosedIsANoOp(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $subscriberClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($subscriberClient, $errstr);
            $subscriber = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $subscriber);

            fwrite($subscriberClient, "*2\r\n\$9\r\nSUBSCRIBE\r\n\$4\r\nnews\r\n");
            $loop->tick(1);
            fread($subscriberClient, 1024);

            // Stands in for the server dropping this connection itself: a
            // write to a peer that has gone away fails, and the connection
            // is closed on the spot - while a PUBLISH already in flight (or
            // the rest of a pipeline) still holds a reference to it.
            $subscriber->close();

            $publisherClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($publisherClient, $errstr);
            $server->acceptClient(5);

            fwrite($publisherClient, "*3\r\n\$7\r\nPUBLISH\r\n\$4\r\nnews\r\n\$5\r\nhello\r\n");
            $loop->tick(1);

            // Delivery was skipped rather than attempted on a closed socket,
            // which is a TypeError in PHP 8 - not a write that just fails.
            self::assertSame(':1' . "\r\n", fread($publisherClient, 1024));

            fclose($subscriberClient);
            fclose($publisherClient);
        } finally {
            $server->stop();
        }
    }

    public function testAClientThatVanishesMidPipelineDoesNotTakeTheServerDown(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            maxWriteBufferBytes: 1000,
        );

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            self::assertInstanceOf(ClientConnection::class, $server->acceptClient(5));

            // Replies far too big to leave the server's write buffer in one
            // flush, pipelined by a client that then vanishes without
            // reading any of them: the first reply's write fails, dropping
            // the connection, and every remaining command in the same read
            // used to be answered onto its now-closed socket.
            $server->store()->set('big', str_repeat('x', 4 * 1024 * 1024));
            fwrite($client, str_repeat("*2\r\n\$3\r\nGET\r\n\$3\r\nbig\r\n", 5));
            usleep(50_000);
            fclose($client);
            usleep(100_000);

            for ($i = 0; $i < 5; $i++) {
                $loop->tick(0.05);
            }

            self::assertSame(0, $server->connectedClientCount());

            $paused = new ReflectionProperty($server, 'pausedConnections');
            self::assertSame([], $paused->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function testMultiQueuesCommandsAndExecRunsThemInOrder(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            fwrite($client, "*1\r\n\$5\r\nMULTI\r\n");
            $loop->tick(1);
            self::assertSame("+OK\r\n", fread($client, 1024));

            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n");
            $loop->tick(1);
            self::assertSame("+QUEUED\r\n", fread($client, 1024));

            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$3\r\nfoo\r\n");
            $loop->tick(1);
            self::assertSame("+QUEUED\r\n", fread($client, 1024));

            fwrite($client, "*1\r\n\$4\r\nEXEC\r\n");
            $loop->tick(1);
            self::assertSame("*2\r\n+OK\r\n\$3\r\nbar\r\n", fread($client, 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testDiscardCancelsAQueuedTransaction(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            fwrite($client, "*1\r\n\$5\r\nMULTI\r\n");
            $loop->tick(1);
            fread($client, 1024);

            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n");
            $loop->tick(1);
            fread($client, 1024);

            fwrite($client, "*1\r\n\$7\r\nDISCARD\r\n");
            $loop->tick(1);
            self::assertSame("+OK\r\n", fread($client, 1024));

            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$3\r\nfoo\r\n");
            $loop->tick(1);
            self::assertSame("\$-1\r\n", fread($client, 1024));

            fclose($client);
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

    public function testMalformedInputGetsARespErrorBeforeOnlyThatClientDisconnects(): void
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

            self::assertStringStartsWith('-ERR Protocol error:', fread($client, 1024));
            self::assertSame(0, $server->connectedClientCount());
            self::assertSame(ConnectionState::Closed, $connection->state());

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testAValidCommandBeforeMalformedInputIsStillExecuted(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            // A good command followed by a desynced stream in the same
            // buffer: the good command must still run before the protocol
            // error takes the connection down.
            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\nnot resp\r\n");
            $loop->tick(1);

            self::assertStringStartsWith("+OK\r\n-ERR Protocol error:", fread($client, 1024));
            self::assertSame('bar', $server->store()->get('foo'));
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

    public function testDataSavedByOneServerIsLoadedByTheNext(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mini-redis-snapshot-');

        try {
            $first = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), snapshotPath: $path);
            $first->store()->set('name', 'Tanat');
            $first->saveSnapshot();

            // The snapshot is written by a forked child (Phase 35), so the
            // file may not exist the instant saveSnapshot() returns; wait
            // for it to land.
            $this->waitForSnapshotFile($path);
            $first->stop();

            $second = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), snapshotPath: $path);

            try {
                self::assertSame('Tanat', $second->store()->get('name'));
            } finally {
                $second->stop();
            }
        } finally {
            @unlink($path);
            foreach (glob($path . '.*.tmp') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    public function testPeriodicSnapshotsSaveWithoutBeingAskedExplicitly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mini-redis-snapshot-');
        $loop = new SelectLoop();

        try {
            $server = new RedisServer(
                new ServerConfig(host: '127.0.0.1', port: 0),
                $loop,
                snapshotPath: $path,
                snapshotIntervalSeconds: 0.01,
            );

            try {
                $server->store()->set('name', 'Tanat');
                usleep(20_000);
                $loop->tick(0.5);

                $this->waitForSnapshotFile($path);

                $reloaded = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), snapshotPath: $path);

                try {
                    self::assertSame('Tanat', $reloaded->store()->get('name'));
                } finally {
                    $reloaded->stop();
                }
            } finally {
                $server->stop();
            }
        } finally {
            @unlink($path);
            foreach (glob($path . '.*.tmp') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    public function testRequestShutdownStopsAcceptingNewConnectionsButDrainsExistingOnes(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            shutdownGraceSeconds: 0.1,
        );

        // Both connect before run() starts: an already-established TCP
        // connection sits in the OS backlog either way, whether or not our
        // application ever calls accept() on it.
        $clientA = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
        self::assertIsResource($clientA, $errstr);
        $clientB = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
        self::assertIsResource($clientB, $errstr);

        $accepted = 0;
        $server->run(function () use ($server, &$accepted): void {
            $accepted++;
            // Shuts down after the very first accept - clientB must never
            // be accepted through the loop from this point on.
            $server->requestShutdown();
        });

        self::assertSame(1, $accepted);
        self::assertSame(0, $server->connectedClientCount());

        fclose($clientA);
        fclose($clientB);
    }

    public function testShutdownClosingConnectionsMidPassDoesNotCrashTheLoop(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            shutdownGraceSeconds: 0.0,
        );

        $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
        self::assertIsResource($client, $errstr);
        self::assertInstanceOf(ClientConnection::class, $server->acceptClient(5));

        // The grace period is already over when the shutdown checker fires,
        // so it closes every connection from inside the same pass that has
        // just selected this client's socket as readable. Dispatching that
        // now-closed socket to its read listener used to be a TypeError -
        // i.e. a graceful shutdown killed the process whenever a client was
        // mid-command.
        $server->requestShutdown();
        usleep(30_000);
        fwrite($client, "*1\r\n\$4\r\nPING\r\n");
        usleep(10_000);

        $loop->tick(1);

        self::assertSame(0, $server->connectedClientCount());

        fclose($client);
    }

    public function testRequestShutdownIsIdempotent(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            shutdownGraceSeconds: 0.05,
        );

        $server->requestShutdown();
        $server->requestShutdown(); // must not register a second checker timer

        $loop->tick(0.2);

        self::assertSame(0, $server->connectedClientCount());
    }

    public function testSigtermTriggersAGracefulShutdown(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            self::markTestSkipped('ext-pcntl / ext-posix not available.');
        }

        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            shutdownGraceSeconds: 0.1,
        );

        // Fires from within the same process's own event loop: kill()-ing
        // yourself still queues a real signal for pcntl_async_signals to
        // deliver at the next safe point, without needing a second process.
        $loop->after(0.02, static function (): void {
            posix_kill(posix_getpid(), SIGTERM);
        });

        $start = microtime(true);
        $server->run();
        $elapsed = microtime(true) - $start;

        // run() actually returned - proof the signal reached
        // requestShutdown() rather than the process just being killed.
        self::assertLessThan(2.0, $elapsed);
        self::assertSame(0, $server->connectedClientCount());
    }

    public function testAConnectionOverTheLimitIsRejectedWithARespErrorAndClosed(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            maxConnections: 1,
        );

        try {
            $clientA = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($clientA, $errstr);
            self::assertInstanceOf(ClientConnection::class, $server->acceptClient(5));
            self::assertSame(1, $server->connectedClientCount());

            $clientB = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($clientB, $errstr);
            self::assertNull($server->acceptClient(5));

            self::assertSame(1, $server->connectedClientCount());
            self::assertStringStartsWith('-ERR max number of clients reached', fread($clientB, 1024));
            self::assertSame('', fread($clientB, 1024));

            fclose($clientA);
            fclose($clientB);
        } finally {
            $server->stop();
        }
    }

    public function testACommandWithTooManyArgumentsIsRejectedWithoutDisconnecting(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            maxArgumentsPerCommand: 2,
        );

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$3\r\nfoo\r\n\$3\r\nbar\r\n");
            $loop->tick(1);
            self::assertSame("-ERR too many arguments\r\n", fread($client, 1024));

            // The connection itself survives: a following, within-limit
            // command still works.
            fwrite($client, "*1\r\n\$4\r\nPING\r\n");
            $loop->tick(1);
            self::assertSame("+PONG\r\n", fread($client, 1024));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testAnOversizedReadBufferGetsARespErrorAndIsDisconnected(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            maxReadBufferBytes: 32,
        );

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            // A simple string whose terminating CRLF never arrives: the
            // parser cannot rule it out the way it can an over-long
            // declared length, so the buffer can only keep growing.
            fwrite($client, '+' . str_repeat('a', 64));
            $loop->tick(1);

            self::assertStringStartsWith('-ERR Protocol error: too big buffer', fread($client, 1024));
            self::assertSame(0, $server->connectedClientCount());
            self::assertSame(ConnectionState::Closed, $connection->state());

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testAValueTooBigForTheReadBufferIsRejectedOnItsDeclaredLength(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            maxReadBufferBytes: 8 * 1024,
        );

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            // Nothing but the header: a value that could never fit in the
            // buffer meant to hold it is answered before its body is sent,
            // rather than after the buffer has filled up with it.
            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$3\r\nbig\r\n\$1000000\r\n");
            $loop->tick(1);

            self::assertStringStartsWith(
                '-ERR Protocol error: Bulk string length 1000000 exceeds',
                (string) fread($client, 1024),
            );
            self::assertSame(0, $server->connectedClientCount());
            self::assertSame(ConnectionState::Closed, $connection->state());

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testASlowReaderIsPausedThenResumedOnceItsWriteBufferDrains(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            maxWriteBufferBytes: 1000,
        );

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            // fread()'s default chunk size (8192 bytes) would otherwise
            // cap every read below, making the drain loop below need far
            // more than a handful of iterations to free enough of the
            // kernel's own send buffer for it to report writable again.
            stream_set_chunk_size($client, 1024 * 1024);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            // Large enough that the client (never read from below) cannot
            // possibly drain it in one fwrite(), leaving well over the 1000
            // byte limit queued - see the write-buffer test above for the
            // same technique.
            $server->store()->set('big', str_repeat('x', 8 * 1024 * 1024));
            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$3\r\nbig\r\n");
            $loop->tick(1);
            self::assertGreaterThan(1000, $connection->writeBuffer()->length());

            // Paused: bytes sent from here on are never read into the
            // connection's ReadBuffer at all, since its readable listener
            // was removed. A command whose effect can be checked directly
            // against the store, rather than by reading its reply back off
            // a socket that still has megabytes of the GET response ahead
            // of it in the stream.
            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$6\r\nmarker\r\n\$4\r\ndone\r\n");
            $loop->tick(0.1);
            self::assertSame(0, $connection->readBuffer()->length());
            self::assertNull($server->store()->get('marker'));

            // Simulate the client having caught up: trim the backlog down
            // to a handful of bytes that a real write can trivially finish,
            // rather than actually reading all several megabytes back on
            // this socket. The socket itself still won't report writable,
            // though, until enough of what is already sitting in the
            // kernel's send buffer has actually been read and acknowledged
            // - a handful of bounded reads gets there.
            $connection->writeBuffer()->consume($connection->writeBuffer()->length() - 5);

            for ($i = 0; $i < 10 && !$connection->writeBuffer()->isEmpty(); $i++) {
                fread($client, 1024 * 1024);
                $loop->tick(0.2);
            }

            self::assertTrue($connection->writeBuffer()->isEmpty());

            // Resumed: the SET queued above is now read and applied.
            $loop->tick(1);
            self::assertSame('done', $server->store()->get('marker'));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testASlowReaderResumesWhenItsWriteBufferHitsTheLowWatermark(): void
    {
        $loop = new SelectLoop();
        // Pause at 16 MB, but resume once the backlog drains to 4 MB - and
        // critically while bytes are still queued, not only once it empties.
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            maxWriteBufferBytes: 16 * 1024 * 1024,
            lowWriteBufferBytes: 4 * 1024 * 1024,
        );

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            stream_set_chunk_size($client, 1024 * 1024);
            $connection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $connection);

            $server->store()->set('big', str_repeat('x', 20 * 1024 * 1024));
            fwrite($client, "*2\r\n\$3\r\nGET\r\n\$3\r\nbig\r\n");
            $loop->tick(1);
            self::assertGreaterThan(16 * 1024 * 1024, $connection->writeBuffer()->length());

            fwrite($client, "*3\r\n\$3\r\nSET\r\n\$6\r\nmarker\r\n\$4\r\ndone\r\n");
            $loop->tick(0.1);
            self::assertSame(0, $connection->readBuffer()->length());
            self::assertNull($server->store()->get('marker'));

            // Drain the backlog down to 6 MB - below the 16 MB pause level
            // but above the 4 MB resumption level - so the connection is
            // still paused when the loop resumes.
            $connection->writeBuffer()->consume($connection->writeBuffer()->length() - 6 * 1024 * 1024);

            // Let the client catch up in megabyte chunks. Each writable event
            // flushes a large slice of the backlog but leaves it well above
            // empty; the moment it crosses 4 MB the connection resumes while
            // megabytes are still queued.
            for ($i = 0; $i < 20 && $connection->writeBuffer()->length() > 4 * 1024 * 1024; $i++) {
                fread($client, 1024 * 1024);
                $loop->tick(0.2);
            }

            self::assertLessThanOrEqual(4 * 1024 * 1024, $connection->writeBuffer()->length());
            self::assertGreaterThan(0, $connection->writeBuffer()->length());

            // Resumed at the low watermark while bytes are still queued: the
            // SET sent while paused is now read and applied.
            $loop->tick(1);
            self::assertSame('done', $server->store()->get('marker'));

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    public function testMetricsTrackRealTrafficAndInfoReportsThem(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(new ServerConfig(host: '127.0.0.1', port: 0), $loop);

        try {
            $client = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($client, $errstr);
            $server->acceptClient(5);

            self::assertSame(1, $server->metrics()->connectionsTotal());

            fwrite($client, "*1\r\n\$4\r\nPING\r\n");
            $loop->tick(1);
            fread($client, 1024);

            fwrite($client, "*1\r\n\$7\r\nUNKNOWN\r\n");
            $loop->tick(1);
            fread($client, 1024);

            self::assertSame(2, $server->metrics()->commandsProcessed());
            self::assertSame(1, $server->metrics()->commandsByType()['PING']);
            self::assertGreaterThan(0, $server->metrics()->bytesRead());
            self::assertGreaterThan(0, $server->metrics()->bytesWritten());
            self::assertSame(1, $server->metrics()->errors());

            fwrite($client, "*1\r\n\$4\r\nINFO\r\n");
            $loop->tick(1);
            $reply = fread($client, 4096);

            self::assertStringContainsString('total_commands_processed:3', $reply);
            self::assertStringContainsString('cmdstat_ping:calls=1', $reply);

            fclose($client);
        } finally {
            $server->stop();
        }
    }

    /**
     * A gap between two otherwise well-tested phases: Phase 19's idle
     * timeout and Phase 20's Pub/Sub each have their own coverage, but
     * nothing combined them - does an idle-timeout disconnect clean up a
     * subscription the same way a client-initiated disconnect does?
     */
    public function testAnIdleTimedOutSubscriberIsUnsubscribedFromItsChannels(): void
    {
        $loop = new SelectLoop();
        $server = new RedisServer(
            new ServerConfig(host: '127.0.0.1', port: 0),
            $loop,
            idleTimeoutSeconds: 0.2,
        );

        try {
            $subscriberClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($subscriberClient, $errstr);
            $subscriberConnection = $server->acceptClient(5);
            self::assertInstanceOf(ClientConnection::class, $subscriberConnection);

            fwrite($subscriberClient, "*2\r\n\$9\r\nSUBSCRIBE\r\n\$4\r\nnews\r\n");
            $loop->tick(1);
            fread($subscriberClient, 1024);

            // Silence until the idle timeout fires and closes it.
            usleep(400_000);
            $loop->tick(0.5);
            self::assertSame(ConnectionState::Closed, $subscriberConnection->state());

            $publisherClient = @stream_socket_client('tcp://' . $server->localAddress(), $errno, $errstr, 5);
            self::assertIsResource($publisherClient, $errstr);
            $server->acceptClient(5);

            fwrite($publisherClient, "*3\r\n\$7\r\nPUBLISH\r\n\$4\r\nnews\r\n\$5\r\nhello\r\n");
            $loop->tick(1);

            self::assertSame(':0' . "\r\n", fread($publisherClient, 1024));

            fclose($subscriberClient);
            fclose($publisherClient);
        } finally {
            $server->stop();
        }
    }

    private function waitForSnapshotFile(string $path): void
    {
        // tempnam() pre-creates an empty file at $path and PHP caches its
        // stat, so wait for the worker's rename() of the serialized data to
        // land - clearing the stat cache each pass or filesize() keeps
        // returning the stale empty size.
        for ($i = 0; $i < 200; $i++) {
            clearstatcache(true, $path);
            if ((filesize($path) ?? 0) > 0) {
                return;
            }
            usleep(10_000);
        }

        self::fail('The forked snapshot worker should have written the file.');
    }
}
