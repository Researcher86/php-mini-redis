#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: does a client that never reads its responses ever bring
 * the server down, or slow it down for anyone else? Phase 26's answer is
 * "no" - the slow connection gets paused instead - and this is that
 * answer, watched rather than just tested.
 *
 * The slow client below pipelines many GETs of a sizeable value without
 * ever reading a single reply, so its own WriteBuffer backs up past the
 * server's default 16 MiB limit. A second, ordinary connection keeps
 * sending PING throughout and is timed on every single one, to show it
 * never notices.
 *
 * The value itself stays under the server's default read-buffer limit
 * (512 KiB) - it is the many queued replies together, not any one of
 * them, that overflows the write side.
 */

require __DIR__ . '/bootstrap.php';

$slow = exampleClient();

$valueSize = 400 * 1024;
$slow->set('big', str_repeat('x', $valueSize));

$requests = 60;

// sendWithoutReading(), not get(): the whole point is a connection whose
// replies nobody collects. Any ordinary call would drain them and there
// would be no backlog to watch.
for ($i = 0; $i < $requests; $i++) {
    $slow->sendWithoutReading('GET', 'big');
}

printf(
    "[slow client] pipelined %d GETs of a %s-byte value (%s total), and will never read a single reply\n\n",
    $requests,
    number_format($valueSize),
    number_format($requests * $valueSize),
);

echo "[healthy client] pinging 10 times while the slow client sits there...\n";
$healthy = exampleClient();

for ($i = 1; $i <= 10; $i++) {
    $start = microtime(true);
    $healthy->ping();
    printf("[healthy client] PING %d answered in %.2f ms\n", $i, (microtime(true) - $start) * 1000);
    usleep(100_000);
}

$healthy->close();
$slow->close();
