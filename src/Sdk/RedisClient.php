<?php

declare(strict_types=1);

namespace App\Sdk;

use App\Protocol\ProtocolException;
use App\Protocol\RespEncoder;
use App\Protocol\RespParser;
use App\Protocol\RespType;
use App\Protocol\RespValue;

/**
 * Client for talking to a running server over TCP - from a CLI script, a
 * PHP-FPM request, a cron job, or the benchmarks in this repository.
 *
 * The commands the server implements are here as named methods:
 *
 *     $client = new RedisClient();
 *     $client->set('name', 'Tanat', ttlSeconds: 60);
 *     $client->get('name');            // 'Tanat'
 *     $client->increment('hits');      // 1
 *
 * Anything else - a command this client has no method for, or a reply it
 * should hand back untouched - goes through command():
 *
 *     $client->command('INFO');
 *
 * Two things are deliberately not hidden behind a nicer API, because
 * hiding them would hide the mechanism they exist to show:
 *
 * - **Pipelining** is `pipeline()`: every command is written before any
 *   reply is read, which is the whole point (one round trip instead of N).
 * - **Transactions** are `multi()` / `queue()` / `exec()`: inside a
 *   transaction the server answers `+QUEUED` rather than a result, so the
 *   named methods above would be lying about what they return.
 *
 * The connection is opened on first use and reused afterwards; a failed
 * write or a closed socket surfaces as an exception rather than a silently
 * dropped command.
 */
final class RedisClient
{
    /** @var resource|null */
    private mixed $socket = null;

    /**
     * Bytes read from the socket that are not a complete reply yet - or are
     * a complete reply nobody has asked for yet, which is the normal case
     * mid-pipeline. A reply is parsed out of here, not straight off the
     * socket, because one read can carry any number of them.
     */
    private string $buffer = '';

    private bool $inTransaction = false;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6380,
        // Applies per reply, not per call: a pipeline of a thousand
        // commands is allowed this long for each answer, not in total.
        private readonly float $timeoutSeconds = 5.0,
        private readonly RespEncoder $encoder = new RespEncoder(),
        private readonly RespParser $parser = new RespParser(),
    ) {
    }

    /** @throws RedisClientException */
    public function ping(?string $message = null): string
    {
        $reply = $message === null
            ? $this->command('PING')
            : $this->command('PING', $message);

        return (string) $reply->value;
    }

    /** @throws RedisClientException */
    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $ttlSeconds === null
            ? $this->command('SET', $key, $value)
            : $this->command('SET', $key, $value, 'EX', (string) $ttlSeconds);
    }

    /**
     * Null means the key is not there - or expired, which the server
     * reports as the same thing.
     *
     * @throws RedisClientException
     */
    public function get(string $key): ?string
    {
        $value = $this->command('GET', $key)->value;

        return $value === null ? null : (string) $value;
    }

    /**
     * @return int how many of $keys existed and were removed
     *
     * @throws RedisClientException
     */
    public function delete(string $key, string ...$more): int
    {
        return (int) $this->command('DEL', $key, ...$more)->value;
    }

    /**
     * @return int how many of $keys exist
     *
     * @throws RedisClientException
     */
    public function exists(string $key, string ...$more): int
    {
        return (int) $this->command('EXISTS', $key, ...$more)->value;
    }

    /** @throws RedisClientException */
    public function increment(string $key): int
    {
        return (int) $this->command('INCR', $key)->value;
    }

    /** @throws RedisClientException */
    public function info(): string
    {
        return (string) $this->command('INFO')->value;
    }

    /**
     * @return int how many subscribers the message reached
     *
     * @throws RedisClientException
     */
    public function publish(string $channel, string $message): int
    {
        return (int) $this->command('PUBLISH', $channel, $message)->value;
    }

    /**
     * Subscribes this connection to $channel and returns how many channels
     * it is now subscribed to. Messages arrive through nextMessage().
     *
     * @throws RedisClientException
     */
    public function subscribe(string $channel): int
    {
        $reply = $this->command('SUBSCRIBE', $channel);

        // *3: ["subscribe", <channel>, <subscription count>].
        return is_array($reply->value) ? (int) $reply->value[2]->value : 0;
    }

    /**
     * Waits for the next message published to a channel this connection is
     * subscribed to, up to $timeoutSeconds (the client's own timeout by
     * default). Null means nothing arrived in time - which is an answer, not
     * a failure, so unlike every other read here it does not throw.
     *
     * @throws RedisClientException
     */
    public function nextMessage(?float $timeoutSeconds = null): ?PubSubMessage
    {
        try {
            $reply = $this->readReply($this->deadline($timeoutSeconds));
        } catch (ReplyTimedOutException) {
            return null;
        }

        if (!is_array($reply->value) || count($reply->value) !== 3) {
            throw new ConnectionLostException('Expected a Pub/Sub message, got something else.');
        }

        return new PubSubMessage(
            (string) $reply->value[1]->value,
            (string) $reply->value[2]->value,
        );
    }

    /**
     * Starts a transaction: from here until exec() or discard(), commands
     * sent with queue() are held by the server instead of run.
     *
     * @throws RedisClientException
     */
    public function multi(): void
    {
        $this->command('MULTI');
        $this->inTransaction = true;
    }

    /**
     * Adds one command to the open transaction. The server replies
     * `+QUEUED`; the actual result arrives in exec()'s array.
     *
     * @throws RedisClientException
     */
    public function queue(string $name, string ...$arguments): void
    {
        $this->command($name, ...$arguments);
    }

    /**
     * Runs the queued commands in order and returns one reply per command.
     * They are returned as RespValues rather than PHP values because a
     * transaction's commands need not have anything in common - a GET's
     * bulk string next to an INCR's integer.
     *
     * @return list<RespValue>
     *
     * @throws RedisClientException
     */
    public function exec(): array
    {
        $reply = $this->command('EXEC');
        $this->inTransaction = false;

        return is_array($reply->value) ? $reply->value : [];
    }

    /** @throws RedisClientException */
    public function discard(): void
    {
        $this->command('DISCARD');
        $this->inTransaction = false;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Sends one command and returns its reply, raising a
     * CommandFailedException if the server answered with an error.
     *
     * @throws RedisClientException
     */
    public function command(string $name, string ...$arguments): RespValue
    {
        $this->writeCommand($name, ...$arguments);

        $reply = $this->readReply($this->deadline(null));

        if ($reply->type === RespType::Error) {
            throw new CommandFailedException((string) $reply->value);
        }

        return $reply;
    }

    /**
     * Sends every command before reading any reply - one round trip for the
     * whole batch instead of one each.
     *
     * Errors come back as error RespValues instead of exceptions: a batch
     * is a batch, and one refused command should not throw away the replies
     * to the other ninety-nine.
     *
     * @param list<list<string>> $commands each an argument list, e.g. ['SET', 'k', 'v']
     *
     * @return list<RespValue> one reply per command, in order
     *
     * @throws RedisClientException
     */
    public function pipeline(array $commands): array
    {
        if ($commands === []) {
            return [];
        }

        $batch = '';

        foreach ($commands as $arguments) {
            $batch .= $this->encode($arguments);
        }

        $this->write($batch);

        $replies = [];

        foreach ($commands as $ignored) {
            $replies[] = $this->readReply($this->deadline(null));
        }

        return $replies;
    }

    /**
     * Writes a command and does not wait for its reply - which is what a
     * client falling behind looks like from the server's side, and the only
     * way to demonstrate backpressure (examples/slow-client.php) or to fill
     * a connection deliberately.
     *
     * Every reply still arrives eventually and stays queued on this
     * connection, so a client that mixes this with ordinary commands will
     * read the earlier replies as answers to the later ones. Use it on a
     * connection doing nothing else.
     *
     * @throws RedisClientException
     */
    public function sendWithoutReading(string $name, string ...$arguments): void
    {
        $this->writeCommand($name, ...$arguments);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
        $this->buffer = '';
        $this->inTransaction = false;
    }

    /** @throws RedisClientException */
    private function writeCommand(string $name, string ...$arguments): void
    {
        $this->write($this->encode([$name, ...$arguments]));
    }

    /** @param list<string> $arguments */
    private function encode(array $arguments): string
    {
        return $this->encoder->encode(RespValue::array(
            array_map(RespValue::bulkString(...), $arguments),
        ));
    }

    /**
     * Writes every byte, however many attempts that takes. The socket is
     * non-blocking, so a large batch is written in whatever slices the
     * kernel's send buffer has room for, waiting for it to drain in between.
     *
     * @throws RedisClientException
     */
    private function write(string $bytes): void
    {
        $socket = $this->connection();
        $deadline = $this->deadline(null);

        while ($bytes !== '') {
            $written = @fwrite($socket, $bytes);

            if ($written === false) {
                $this->close();

                throw new ConnectionLostException('The connection was closed while sending a command.');
            }

            $bytes = substr($bytes, $written);

            if ($bytes !== '') {
                $this->awaitWritable($socket, $deadline);
            }
        }
    }

    /**
     * Returns the next complete reply, reading more bytes only when the
     * ones already buffered do not hold one.
     *
     * @throws RedisClientException
     */
    private function readReply(float $deadline): RespValue
    {
        while (true) {
            try {
                $parsed = $this->parser->parse($this->buffer);
            } catch (ProtocolException $exception) {
                $this->close();

                throw new ConnectionLostException(
                    sprintf('The server sent something that is not RESP: %s', $exception->getMessage()),
                );
            }

            if ($parsed !== null) {
                [$value, $consumed] = $parsed;
                $this->buffer = substr($this->buffer, $consumed);

                return $value;
            }

            $this->buffer .= $this->readChunk($deadline);
        }
    }

    /** @throws RedisClientException */
    private function readChunk(float $deadline): string
    {
        $socket = $this->connection();
        $this->awaitReadable($socket, $deadline);

        $chunk = @fread($socket, 65536);

        // An empty read on a socket that select() just called readable means
        // the peer closed it - the server drops a connection on a protocol
        // error, past its client limit, or when a subscriber falls behind.
        if ($chunk === false || $chunk === '') {
            $this->close();

            throw new ConnectionLostException('The server closed the connection.');
        }

        return $chunk;
    }

    /**
     * @param resource $socket
     *
     * @throws ReplyTimedOutException
     */
    private function awaitReadable(mixed $socket, float $deadline): void
    {
        $read = [$socket];
        $write = [];

        if (!$this->select($read, $write, $deadline)) {
            throw new ReplyTimedOutException(
                sprintf('No reply within %.3f seconds.', $this->timeoutSeconds),
            );
        }
    }

    /**
     * @param resource $socket
     *
     * @throws ReplyTimedOutException
     */
    private function awaitWritable(mixed $socket, float $deadline): void
    {
        $read = [];
        $write = [$socket];

        if (!$this->select($read, $write, $deadline)) {
            throw new ReplyTimedOutException(
                sprintf('The server stopped accepting bytes within %.3f seconds.', $this->timeoutSeconds),
            );
        }
    }

    /**
     * @param list<resource> $read
     * @param list<resource> $write
     */
    private function select(array $read, array $write, float $deadline): bool
    {
        $remaining = max(0.0, $deadline - microtime(true));
        $except = null;

        // stream_select() takes whole seconds plus a microsecond remainder,
        // not one float - the same split SelectLoop makes on the server side.
        $seconds = (int) floor($remaining);
        $microseconds = (int) (($remaining - $seconds) * 1_000_000);

        return (bool) @stream_select($read, $write, $except, $seconds, $microseconds);
    }

    private function deadline(?float $timeoutSeconds): float
    {
        return microtime(true) + ($timeoutSeconds ?? $this->timeoutSeconds);
    }

    /**
     * @return resource
     *
     * @throws ConnectionFailedException
     */
    private function connection(): mixed
    {
        if (is_resource($this->socket)) {
            return $this->socket;
        }

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeoutSeconds,
        );

        if ($socket === false) {
            throw new ConnectionFailedException(
                sprintf('Could not connect to %s:%d: %s (%d)', $this->host, $this->port, $errstr, $errno),
            );
        }

        // Non-blocking, so every wait in this class is one the client
        // controls: a server that stops answering costs $timeoutSeconds,
        // not the rest of the process's life.
        stream_set_blocking($socket, false);

        return $this->socket = $socket;
    }
}
