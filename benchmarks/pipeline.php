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

printf("Pipelined PING throughput by batch size (commands/sec, higher is better)\n\n");

foreach ([1, 10, 100, 1000, 5000] as $batchSize) {
    $client = exampleClient(timeoutSeconds: 30.0);
    $batch = array_fill(0, $batchSize, ['PING']);

    $start = microtime(true);
    $replies = $client->pipeline($batch);
    $seconds = microtime(true) - $start;

    $client->close();

    if (count($replies) !== $batchSize) {
        fwrite(STDERR, sprintf("Expected %d replies, got %d\n", $batchSize, count($replies)));

        exit(1);
    }

    printf("batch %6d  %10.0f cmd/s\n", $batchSize, $batchSize / max($seconds, 1e-9));
}
