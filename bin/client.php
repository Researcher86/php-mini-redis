#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Protocol\RespEncoder;
use App\Protocol\RespParser;
use App\Protocol\RespType;
use App\Protocol\RespValue;

require dirname(__DIR__) . '/vendor/autoload.php';

function describe(RespValue $value): string
{
    return match ($value->type) {
        RespType::SimpleString, RespType::Integer => (string) $value->value,
        RespType::Error => 'ERROR: ' . $value->value,
        RespType::BulkString => $value->value === null ? '(nil)' : $value->value,
        RespType::Array => $value->value === null
            ? '(nil)'
            : implode(', ', array_map(describe(...), $value->value)),
    };
}

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6380);

$arguments = array_slice($argv, 1); // @phpstan-ignore variable.undefined ($argv is always set for a CLI script)

if ($arguments === []) {
    $arguments = ['PING'];
}

$socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, 5);

if ($socket === false) {
    fwrite(STDERR, sprintf("Could not connect to %s:%d: %s (%d)\n", $host, $port, $errstr, $errno));
    exit(1);
}

$command = RespValue::array(array_map(RespValue::bulkString(...), $arguments));
fwrite($socket, (new RespEncoder())->encode($command));

$parser = new RespParser();
$buffer = '';
$parsed = null;

while ($parsed === null) {
    $chunk = fread($socket, 65536);

    if ($chunk === false || $chunk === '') {
        fwrite(STDERR, "Connection closed before a reply arrived.\n");
        exit(1);
    }

    $buffer .= $chunk;
    $parsed = $parser->parse($buffer);
}

[$value] = $parsed;

fwrite(STDOUT, describe($value) . "\n");
fclose($socket);
