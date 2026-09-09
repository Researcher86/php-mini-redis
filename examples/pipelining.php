#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: how much does pipelining - sending several commands
 * without waiting for each reply - actually save, compared to the
 * request/wait/request/wait pattern every other example in this
 * directory uses?
 */

require __DIR__ . '/bootstrap.php';

const ROUND_TRIPS = 500;

$sequential = exampleClient();
$start = microtime(true);

for ($i = 0; $i < ROUND_TRIPS; $i++) {
    $sequential->ping();
}

$sequentialSeconds = microtime(true) - $start;
$sequential->close();

// The same commands, written back-to-back before any reply is read: one
// round trip for the batch instead of one each. The client still reads
// every reply, in order - the saving is in the waiting, not in the work.
$pipelined = exampleClient();
$start = microtime(true);

$replies = $pipelined->pipeline(array_fill(0, ROUND_TRIPS, ['PING']));

$pipelinedSeconds = microtime(true) - $start;
$pipelined->close();

printf("%d PING round trips, one at a time: %.3fs\n", ROUND_TRIPS, $sequentialSeconds);
printf("%d PINGs pipelined, replies read after:  %.3fs (%d replies)\n", ROUND_TRIPS, $pipelinedSeconds, count($replies));
printf("Pipelining was %.1fx faster here.\n", $sequentialSeconds / max($pipelinedSeconds, 0.000001));
