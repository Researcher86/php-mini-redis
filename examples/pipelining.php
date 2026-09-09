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

use App\Protocol\RespEncoder;
use App\Protocol\RespParser;
use App\Protocol\RespValue;

const ROUND_TRIPS = 500;

$sequential = exampleConnect();
$start = microtime(true);

for ($i = 0; $i < ROUND_TRIPS; $i++) {
    exampleCommand($sequential, ['PING']);
}

$sequentialSeconds = microtime(true) - $start;
fclose($sequential);

$pipelined = exampleConnect();
$start = microtime(true);

$encoder = new RespEncoder();
$batch = str_repeat($encoder->encode(RespValue::array([RespValue::bulkString('PING')])), ROUND_TRIPS);
fwrite($pipelined, $batch);

// Several replies can arrive concatenated in one read, unlike a single
// request/reply exchange - so this keeps one buffer across every read,
// consuming exactly one reply's worth of bytes at a time, rather than
// starting fresh (and losing whatever followed) on each call.
$parser = new RespParser();
$buffer = '';
$received = 0;

while ($received < ROUND_TRIPS) {
    $parsed = $parser->parse($buffer);

    if ($parsed === null) {
        $chunk = fread($pipelined, 65536);

        if ($chunk === false || $chunk === '') {
            fwrite(STDERR, "Connection closed early.\n");
            exit(1);
        }

        $buffer .= $chunk;

        continue;
    }

    [, $consumed] = $parsed;
    $buffer = substr($buffer, $consumed);
    $received++;
}

$pipelinedSeconds = microtime(true) - $start;
fclose($pipelined);

printf("%d PING round trips, one at a time: %.3fs\n", ROUND_TRIPS, $sequentialSeconds);
printf("%d PINGs pipelined, replies read after:  %.3fs\n", ROUND_TRIPS, $pipelinedSeconds);
printf("Pipelining was %.1fx faster here.\n", $sequentialSeconds / max($pipelinedSeconds, 0.000001));
