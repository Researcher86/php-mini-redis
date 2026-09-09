<?php

declare(strict_types=1);

namespace App\Server;

use App\Command\Command;
use App\Command\CommandDispatcher;
use App\Command\CommandException;
use App\Connection\ClientConnection;
use App\Connection\ConnectionManager;
use App\Connection\ConnectionState;
use App\EventLoop\EventLoop;
use App\EventLoop\SelectLoop;
use App\Protocol\ProtocolException;
use App\Protocol\RespEncoder;
use App\Protocol\RespStreamReader;
use App\Protocol\RespValue;
use App\Storage\InMemoryStore;
use App\Storage\Store;

/**
 * Accepts TCP clients through an EventLoop, parses complete RESP values
 * out of each connection's ReadBuffer, dispatches them as commands
 * against a Store, and writes the encoded result back.
 *
 * A timer periodically sweeps expired keys from the Store (active
 * expiration), on top of the Store's own lazy expiration on access.
 */
final class RedisServer
{
    private const int READ_CHUNK_SIZE = 65536;

    private ServerSocket $socket;
    private ConnectionManager $connections;
    private EventLoop $eventLoop;
    private CommandDispatcher $dispatcher;
    private Store $store;
    private RespStreamReader $streamReader;
    private RespEncoder $encoder;
    private float $expirationSweepIntervalSeconds;

    public function __construct(
        ServerConfig $config,
        ?EventLoop $eventLoop = null,
        ?CommandDispatcher $dispatcher = null,
        ?Store $store = null,
        float $expirationSweepIntervalSeconds = 1.0,
    ) {
        $this->socket = new ServerSocket($config);
        $this->connections = new ConnectionManager();
        $this->eventLoop = $eventLoop ?? new SelectLoop();
        $this->dispatcher = $dispatcher ?? CommandDispatcher::withDefaultHandlers();
        $this->store = $store ?? new InMemoryStore();
        $this->streamReader = new RespStreamReader();
        $this->encoder = new RespEncoder();
        $this->expirationSweepIntervalSeconds = $expirationSweepIntervalSeconds;

        // Active expiration: expired keys are also removed on a timer,
        // instead of only being noticed lazily the next time they are read.
        $this->eventLoop->every($this->expirationSweepIntervalSeconds, function (): void {
            $this->store->sweepExpired();
        });
    }

    public function localAddress(): string
    {
        return $this->socket->localAddress();
    }

    public function store(): Store
    {
        return $this->store;
    }

    /**
     * Blocks until a client connects (or the timeout elapses), and starts
     * tracking it. Useful outside the event loop, e.g. in tests.
     */
    public function acceptClient(?float $timeoutSeconds = null): ?ClientConnection
    {
        $socket = $this->socket->accept($timeoutSeconds);

        if ($socket === false) {
            return null;
        }

        $connection = new ClientConnection($socket);
        $connection->setState(ConnectionState::Connected);
        $this->connections->add($connection);
        $this->watchForIncomingData($connection);

        return $connection;
    }

    public function connections(): ConnectionManager
    {
        return $this->connections;
    }

    public function connectedClientCount(): int
    {
        return $this->connections->count();
    }

    /**
     * Registers the listening socket with the event loop and runs it.
     *
     * @param (callable(ClientConnection): void)|null $onConnect
     */
    public function run(?callable $onConnect = null): void
    {
        $this->eventLoop->onReadable($this->socket->resource(), function () use ($onConnect): void {
            $connection = $this->acceptClient(0);

            if ($connection !== null && $onConnect !== null) {
                $onConnect($connection);
            }
        });

        $this->eventLoop->run();
    }

    public function stop(): void
    {
        $this->eventLoop->stop();
        $this->connections->closeAll();
        $this->socket->close();
    }

    /**
     * Reads whatever is available into the connection's ReadBuffer whenever
     * its socket becomes readable, executes every complete command found,
     * and disconnects the client once it goes away.
     */
    private function watchForIncomingData(ClientConnection $connection): void
    {
        $this->eventLoop->onReadable($connection->socket(), function () use ($connection): void {
            $chunk = @fread($connection->socket(), self::READ_CHUNK_SIZE);

            if ($chunk === false || ($chunk === '' && feof($connection->socket()))) {
                $this->disconnectClient($connection);

                return;
            }

            if ($chunk === '') {
                return;
            }

            $connection->setState(ConnectionState::Reading);
            $connection->appendToReadBuffer($chunk);
            $this->processBufferedCommands($connection);
        });
    }

    private function processBufferedCommands(ClientConnection $connection): void
    {
        try {
            [$values, $consumed] = $this->streamReader->readAll($connection->readBuffer()->contents());
        } catch (ProtocolException) {
            // Phase 24 will reply with a proper RESP error; for now a
            // malformed stream simply ends the connection instead of
            // taking the rest of the server down with it.
            $this->disconnectClient($connection);

            return;
        }

        if ($consumed > 0) {
            $connection->readBuffer()->consume($consumed);
        }

        foreach ($values as $value) {
            $this->executeValue($connection, $value);
        }
    }

    private function executeValue(ClientConnection $connection, RespValue $value): void
    {
        $connection->setState(ConnectionState::Processing);

        try {
            $command = Command::fromRespValue($value);
            $result = $this->dispatcher->dispatch($command, $this->store);
        } catch (CommandException $exception) {
            $result = RespValue::error('ERR ' . $exception->getMessage());
        }

        $connection->setState(ConnectionState::Writing);
        $connection->appendToWriteBuffer($this->encoder->encode($result));
        $this->flushWriteBuffer($connection);
    }

    /**
     * Writes as much of the connection's WriteBuffer as the socket accepts
     * right now. Whatever does not fit stays queued, and the socket is
     * watched for the next writable event instead of blocking on it.
     */
    private function flushWriteBuffer(ClientConnection $connection): void
    {
        $buffer = $connection->writeBuffer();

        if ($buffer->isEmpty()) {
            return;
        }

        $written = @fwrite($connection->socket(), $buffer->contents());

        if ($written === false) {
            $this->disconnectClient($connection);

            return;
        }

        if ($written > 0) {
            $buffer->consume($written);
        }

        if ($buffer->isEmpty()) {
            $this->eventLoop->removeWritable($connection->socket());
            $connection->setState(ConnectionState::Reading);

            return;
        }

        $this->eventLoop->onWritable($connection->socket(), function () use ($connection): void {
            $this->flushWriteBuffer($connection);
        });
    }

    private function disconnectClient(ClientConnection $connection): void
    {
        $this->eventLoop->removeReadable($connection->socket());
        $this->eventLoop->removeWritable($connection->socket());
        $this->connections->remove($connection);
        $connection->close();
    }
}
