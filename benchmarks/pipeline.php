#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Load: pipelining. One connection sends a batch of commands back-to-back
 * without waiting per command, then reads the replies. Reports sustained
 * pipelined throughput (commands/sec) for a range of batch sizes, to show
 * how much of the per-command cost pipelining removes.
 *
 * Requires a running server (make run-server) in another terminal.
 */

require dirname(__DIR__) . '/examples/bootstrap.php';

use App\Protocol\RespEncoder;
use App\Protocol\RespParser;
use App\Protocol\RespValue;

$encoder = new RespEncoder();
$ping = $encoder->encode(RespValue::array([RespValue::bulkString('PING')]));

printf("Pipelined PING throughput by batch size (commands/sec, higher is better)\n\n");

foreach ([1, 10, 100, 1000, 5000] as $batchSize) {
    $socket = exampleConnect();

    $batch = str_repeat($ping, $batchSize);
    fwrite($socket, $batch);

    $parser = new RespParser();
    $buffer = '';
    $received = 0;
    $start = microtime(true);

    while ($received < $batchSize) {
        $parsed = $parser->parse($buffer);

        if ($parsed === null) {
            $chunk = fread($socket, 65536);

            if ($chunk === false || $chunk === '') {
                fwrite(STDERR, "Connection closed early at $received/$batchSize.\n");
                exit(1);
            }

            $buffer .= $chunk;

            continue;
        }

        [, $consumed] = $parsed;
        $buffer = substr($buffer, $consumed);
        $received++;
    }

    $seconds = microtime(true) - $start;
    fclose($socket);

    printf("batch %6d  %10.0f cmd/s\n", $batchSize, $batchSize / max($seconds, 1e-9));
}
