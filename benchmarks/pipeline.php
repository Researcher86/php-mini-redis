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

// The same number of commands at every batch size, so the sizes can
// actually be compared: one batch of 5,000 against 5,000 batches of one,
// rather than "one round trip" against "5,000 round trips".
const COMMANDS_PER_SIZE = 5000;

foreach ([1, 10, 100, 1000, 5000] as $batchSize) {
    $client = exampleClient(timeoutSeconds: 30.0);
    $batch = array_fill(0, $batchSize, ['PING']);
    $rounds = intdiv(COMMANDS_PER_SIZE, $batchSize);

    $start = microtime(true);

    for ($i = 0; $i < $rounds; $i++) {
        $replies = $client->pipeline($batch);

        if (count($replies) !== $batchSize) {
            fwrite(STDERR, sprintf("Expected %d replies, got %d\n", $batchSize, count($replies)));

            exit(1);
        }
    }

    $seconds = microtime(true) - $start;
    $client->close();

    printf("batch %6d  %10.0f cmd/s\n", $batchSize, ($rounds * $batchSize) / max($seconds, 1e-9));
}
