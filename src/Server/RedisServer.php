<?php

declare(strict_types=1);

namespace App\Server;

use App\Command\Command;
use App\Command\CommandDispatcher;
use App\Command\CommandException;
use App\Command\Handler\DiscardCommand;
use App\Command\Handler\ExecCommand;
use App\Command\Handler\InfoCommand;
use App\Command\Handler\MultiCommand;
use App\Command\Handler\PublishCommand;
use App\Command\Handler\SubscribeCommand;
use App\Connection\ClientConnection;
use App\Connection\ConnectionManager;
use App\Connection\ConnectionState;
use App\EventLoop\EventLoop;
use App\EventLoop\SelectLoop;
use App\Metrics\ServerMetrics;
use App\Persistence\SnapshotStore;
use App\Protocol\RespEncoder;
use App\Protocol\RespStreamReader;
use App\Protocol\RespType;
use App\Protocol\RespValue;
use App\PubSub\ChannelRegistry;
use App\Storage\InMemoryStore;
use App\Storage\Store;
use App\Support\Clock;
use App\Support\SystemClock;
use App\Transaction\TransactionManager;

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
    private ?float $idleTimeoutSeconds;
    private ChannelRegistry $channels;
    private TransactionManager $transactions;
    private ?SnapshotStore $snapshots;
    private ServerMetrics $metrics;
    private bool $shuttingDown = false;

    /** @var array<int, true> Connection ids currently paused for reading. */
    private array $pausedConnections = [];

    public function __construct(
        ServerConfig $config,
        ?EventLoop $eventLoop = null,
        ?CommandDispatcher $dispatcher = null,
        ?Store $store = null,
        float $expirationSweepIntervalSeconds = 1.0,
        ?float $idleTimeoutSeconds = null,
        ?string $snapshotPath = null,
        ?float $snapshotIntervalSeconds = null,
        private readonly Clock $clock = new SystemClock(),
        private readonly float $shutdownGraceSeconds = 5.0,
        // A read buffer that grows past this without ever yielding a
        // complete value is either a broken client or a hostile one -
        // either way, left unbounded it is a memory-exhaustion vector.
        private readonly int $maxReadBufferBytes = 512 * 1024,
        // Protects against a single command with an absurd number of
        // arguments (e.g. a scripted client gone wrong), independent of
        // the byte-size limit above.
        private readonly int $maxArgumentsPerCommand = 1024,
        // Null means unbounded.
        private readonly ?int $maxConnections = null,
        // How much a connection's WriteBuffer may hold before reading from
        // it is paused - a slow reader (or one that never reads at all)
        // must not let responses queue up without bound while its socket
        // stays open. Reading resumes once the buffer fully drains.
        private readonly int $maxWriteBufferBytes = 16 * 1024 * 1024,
    ) {
        $this->socket = new ServerSocket($config);
        $this->connections = new ConnectionManager();
        $this->eventLoop = $eventLoop ?? new SelectLoop();
        $this->dispatcher = $dispatcher ?? CommandDispatcher::withDefaultHandlers();
        $this->store = $store ?? new InMemoryStore();
        $this->streamReader = new RespStreamReader();
        $this->encoder = new RespEncoder();
        $this->expirationSweepIntervalSeconds = $expirationSweepIntervalSeconds;
        $this->idleTimeoutSeconds = $idleTimeoutSeconds;
        $this->snapshots = $snapshotPath === null ? null : new SnapshotStore($snapshotPath);
        $this->metrics = new ServerMetrics();

        if ($this->snapshots !== null && $this->store instanceof InMemoryStore) {
            $this->snapshots->load($this->store);
        }

        // Pub/Sub needs access to connections beyond the one issuing the
        // command (PUBLISH writes to every subscriber), which plain
        // CommandHandlers don't otherwise have a way to do.
        $this->channels = new ChannelRegistry();
        $this->dispatcher->register('SUBSCRIBE', new SubscribeCommand($this->channels));
        $this->dispatcher->register('PUBLISH', new PublishCommand(
            $this->channels,
            function (ClientConnection $subscriber, string $payload): void {
                $this->queueForWrite($subscriber, $payload);
            },
        ));

        // Transactions: MULTI/EXEC/DISCARD need to intercept normal command
        // execution (queue instead of run), handled in executeValue().
        $this->transactions = new TransactionManager();
        $this->dispatcher->register('MULTI', new MultiCommand($this->transactions));
        $this->dispatcher->register('DISCARD', new DiscardCommand($this->transactions));
        $this->dispatcher->register('EXEC', new ExecCommand($this->transactions, $this->dispatcher));

        $this->dispatcher->register('INFO', new InfoCommand($this->metrics, $this->connections));

        // Active expiration: expired keys are also removed on a timer,
        // instead of only being noticed lazily the next time they are read.
        $this->eventLoop->every($this->expirationSweepIntervalSeconds, function (): void {
            $this->metrics->recordExpiredKeys($this->store->sweepExpired());
        });

        if ($this->idleTimeoutSeconds !== null) {
            $this->eventLoop->every($this->idleTimeoutSeconds, function (): void {
                $this->closeIdleConnections();
            });
        }

        if ($this->snapshots !== null && $snapshotIntervalSeconds !== null) {
            $this->eventLoop->every($snapshotIntervalSeconds, function (): void {
                $this->saveSnapshot();
            });
        }
    }

    /**
     * Writes the store's current contents to the configured snapshot path.
     * A no-op if no snapshot path was configured, or the store isn't an
     * InMemoryStore.
     */
    public function saveSnapshot(): void
    {
        if ($this->snapshots !== null && $this->store instanceof InMemoryStore) {
            $this->snapshots->save($this->store);
        }
    }

    public function localAddress(): string
    {
        return $this->socket->localAddress();
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function metrics(): ServerMetrics
    {
        return $this->metrics;
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

        if ($this->maxConnections !== null && $this->connections->count() >= $this->maxConnections) {
            @fwrite($socket, $this->encoder->encode(RespValue::error('ERR max number of clients reached')));
            fclose($socket);

            return null;
        }

        $connection = new ClientConnection($socket);
        $connection->setState(ConnectionState::Connected);
        $this->connections->add($connection);
        $this->watchForIncomingData($connection);
        $this->metrics->recordConnection();

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
        $this->installSignalHandlers();

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
     * Starts a graceful shutdown: stop accepting new connections, but leave
     * existing ones running - so whatever they are mid-write can still be
     * flushed - until either every connection is gone or
     * $this->shutdownGraceSeconds has passed, whichever comes first.
     * Idempotent: a second call while one is already in progress is a
     * no-op.
     */
    public function requestShutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        $this->eventLoop->removeReadable($this->socket->resource());

        $deadline = $this->clock->now() + $this->shutdownGraceSeconds;
        $checker = $this->eventLoop->every(0.02, function () use (&$checker, $deadline): void {
            if ($this->connections->count() > 0 && $this->clock->now() < $deadline) {
                return;
            }

            $checker->cancel();
            $this->stop();
        });
    }

    /**
     * Reacts to SIGTERM/SIGINT with requestShutdown() instead of the
     * default "terminate immediately". A no-op where ext-pcntl isn't
     * available.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->requestShutdown();
        });
        pcntl_signal(SIGINT, function (): void {
            $this->requestShutdown();
        });
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
            $this->metrics->recordBytesRead(strlen($chunk));
            $this->processBufferedCommands($connection);

            // processBufferedCommands() may already have disconnected this
            // connection (a malformed stream) - nothing left to check.
            if ($connection->state() === ConnectionState::Closed) {
                return;
            }

            if ($connection->readBuffer()->length() > $this->maxReadBufferBytes) {
                $this->sendErrorAndDisconnect($connection, 'ERR Protocol error: too big buffer');
            }
        });
    }

    private function processBufferedCommands(ClientConnection $connection): void
    {
        [$values, $consumed, $error] = $this->streamReader->readAll($connection->readBuffer()->contents());

        if ($consumed > 0) {
            $connection->readBuffer()->consume($consumed);
        }

        foreach ($values as $value) {
            $this->executeValue($connection, $value);
        }

        if ($error === null) {
            return;
        }

        // Unlike a command-level error (wrong argument count, an unknown
        // name), a desynced byte stream cannot be resynchronized - there
        // is no reliable way to find the start of the next value once
        // framing is lost. Everything that arrived before the bad byte
        // has already been applied in order above; the client still gets
        // a proper RESP error, the same as real Redis, before the
        // connection closes.
        $this->sendErrorAndDisconnect($connection, 'ERR Protocol error: ' . $error->getMessage());
    }

    private function executeValue(ClientConnection $connection, RespValue $value): void
    {
        $connection->setState(ConnectionState::Processing);

        if ($value->type === RespType::Array && is_array($value->value) && count($value->value) > $this->maxArgumentsPerCommand) {
            $this->metrics->recordError();
            $connection->setState(ConnectionState::Writing);
            $this->queueForWrite($connection, $this->encoder->encode(RespValue::error('ERR too many arguments')));

            return;
        }

        try {
            $command = Command::fromRespValue($value);
            $this->metrics->recordCommand($command->name);

            if ($this->transactions->isActive($connection) && !in_array($command->name, ['MULTI', 'EXEC', 'DISCARD'], true)) {
                $this->transactions->queue($connection, $command);
                $result = RespValue::simpleString('QUEUED');
            } else {
                $result = $this->dispatcher->dispatch($command, $this->store, $connection);
            }
        } catch (CommandException $exception) {
            $result = RespValue::error('ERR ' . $exception->getMessage());
        }

        if ($result->type === RespType::Error) {
            $this->metrics->recordError();
        }

        $connection->setState(ConnectionState::Writing);
        $this->queueForWrite($connection, $this->encoder->encode($result));
    }

    /**
     * Appends $bytes to the connection's WriteBuffer, flushes as much as
     * the socket accepts right now, and pauses reading from it if what is
     * left queued is over the backpressure limit.
     */
    private function queueForWrite(ClientConnection $connection, string $bytes): void
    {
        $connection->appendToWriteBuffer($bytes);
        $this->flushWriteBuffer($connection);
        $this->pauseReadingIfWriteBufferTooLarge($connection);
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
            $this->metrics->recordBytesWritten($written);
        }

        if ($buffer->isEmpty()) { // @phpstan-ignore if.alwaysFalse (PHPStan can't see WriteBuffer::consume() change the buffer)
            $this->eventLoop->removeWritable($connection->socket());
            $connection->setState(ConnectionState::Reading);
            $this->resumeReadingIfPaused($connection);

            return;
        }

        $this->eventLoop->onWritable($connection->socket(), function () use ($connection): void {
            $this->flushWriteBuffer($connection);
        });
    }

    /**
     * Backpressure: a connection whose queued WriteBuffer is over the
     * limit stops being read from - a slow or absent reader must not be
     * allowed to make the server buffer an unbounded amount of its own
     * responses in memory. Reading resumes once the buffer fully drains
     * (see flushWriteBuffer()).
     */
    private function pauseReadingIfWriteBufferTooLarge(ClientConnection $connection): void
    {
        if ($connection->writeBuffer()->length() <= $this->maxWriteBufferBytes) {
            return;
        }

        if (isset($this->pausedConnections[$connection->id()])) {
            return;
        }

        $this->pausedConnections[$connection->id()] = true;
        $this->eventLoop->removeReadable($connection->socket());
    }

    private function resumeReadingIfPaused(ClientConnection $connection): void
    {
        if (!isset($this->pausedConnections[$connection->id()])) {
            return;
        }

        unset($this->pausedConnections[$connection->id()]);
        $this->watchForIncomingData($connection);
    }

    /**
     * Closes every connection that has not shown any activity for at least
     * $this->idleTimeoutSeconds.
     */
    private function closeIdleConnections(): void
    {
        $now = $this->clock->now();

        foreach ($this->connections->all() as $connection) {
            if ($now - $connection->lastActivityAt() >= $this->idleTimeoutSeconds) {
                $this->disconnectClient($connection);
            }
        }
    }

    /**
     * Best-effort: the connection is being torn down immediately after, so
     * this writes directly rather than queuing through WriteBuffer - there
     * is no next tick left for a partial write to finish on.
     */
    private function sendErrorAndDisconnect(ClientConnection $connection, string $message): void
    {
        $this->metrics->recordError();
        @fwrite($connection->socket(), $this->encoder->encode(RespValue::error($message)));
        $this->disconnectClient($connection);
    }

    private function disconnectClient(ClientConnection $connection): void
    {
        $this->eventLoop->removeReadable($connection->socket());
        $this->eventLoop->removeWritable($connection->socket());
        $this->connections->remove($connection);
        $this->channels->unsubscribeAll($connection);
        $this->transactions->discard($connection);
        unset($this->pausedConnections[$connection->id()]);
        $connection->close();
    }
}
