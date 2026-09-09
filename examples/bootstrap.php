<?php

declare(strict_types=1);

use App\Protocol\RespEncoder;
use App\Protocol\RespParser;
use App\Protocol\RespValue;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Shared by every script under examples/: each one answers one question
 * against a real, already-running server (start it first with
 * `make run-server`), so this just holds the connect/send/receive
 * boilerplate none of them are actually about.
 */
function exampleConnect(): mixed
{
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6380);

    $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, 5);

    if ($socket === false) {
        fwrite(STDERR, sprintf("Could not connect to %s:%d: %s (%d) - is `make run-server` running?\n", $host, $port, $errstr, $errno));
        exit(1);
    }

    return $socket;
}

/**
 * @param resource $socket
 * @param list<string> $arguments
 */
function exampleSend(mixed $socket, array $arguments): void
{
    $command = RespValue::array(array_map(RespValue::bulkString(...), $arguments));
    fwrite($socket, (new RespEncoder())->encode($command));
}

/** @param resource $socket */
function exampleReceive(mixed $socket): RespValue
{
    $parser = new RespParser();
    $buffer = '';

    while (($parsed = $parser->parse($buffer)) === null) {
        $chunk = fread($socket, 65536);

        if ($chunk === false || $chunk === '') {
            fwrite(STDERR, "Connection closed before a reply arrived.\n");
            exit(1);
        }

        $buffer .= $chunk;
    }

    [$value] = $parsed;

    return $value;
}

/** @param list<string> $arguments */
function exampleCommand(mixed $socket, array $arguments): RespValue
{
    exampleSend($socket, $arguments);

    return exampleReceive($socket);
}
