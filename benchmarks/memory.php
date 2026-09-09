#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Load: memory. Writes $keys keys (with and without TTL) into a running
 * server and reports the server process's current and peak RSS before and
 * after, so the store's memory cost is visible rather than assumed.
 *
 * Requires a running server (make run-server) in another terminal - and
 * ideally a server started inside the same container/namespace so its
 * /proc/<pid>/status is readable from here.
 */

require dirname(__DIR__) . '/examples/bootstrap.php';

const KEYS = 100_000;
const VALUE_SIZE = 64;

/**
 * Returns [rssBytes, peakBytes] for a PID from /proc, or [0, 0] if it
 * can't be read (e.g. running on a platform without /proc).
 *
 * @return array{0: int, 1: int}
 */
function serverMemory(int $pid): array
{
    $status = @file_get_contents('/proc/' . $pid . '/status');

    if ($status === false) {
        return [0, 0];
    }

    $rss = 0;
    $peak = 0;

    foreach (explode("\n", $status) as $line) {
        if (str_starts_with($line, 'VmRSS:')) {
            $rss = (int) preg_replace('/\D/', '', $line) * 1024;
        } elseif (str_starts_with($line, 'VmPeak:')) {
            $peak = (int) preg_replace('/\D/', '', $line) * 1024;
        }
    }

    return [$rss, $peak];
}

$serverPid = (int) (getenv('SERVER_PID') ?: 0);

if ($serverPid <= 0) {
    fwrite(STDERR, "Set SERVER_PID to the server process's PID (the container's PID 1 in Docker) to report its memory.\n");
}

[$rssBefore, $peakBefore] = serverMemory($serverPid);

$socket = exampleConnect();

$value = str_repeat('x', VALUE_SIZE);
$prefix = 'mem:' . getmypid() . ':';
$start = microtime(true);

for ($i = 0; $i < KEYS; $i++) {
    exampleCommand($socket, ['SET', $prefix . $i, $value]);
}

$elapsed = microtime(true) - $start;

[$rssAfter, $peakAfter] = serverMemory($serverPid);

printf("Keys written:      %d\n", KEYS);
printf("Value size:        %d bytes\n", VALUE_SIZE);
printf("Write time:        %.3fs (%.0f writes/s)\n", $elapsed, KEYS / max($elapsed, 1e-9));
printf("Server RSS before: %s\n", $rssBefore > 0 ? formatBytes($rssBefore) : 'n/a');
printf("Server RSS after:  %s\n", $rssAfter > 0 ? formatBytes($rssAfter) : 'n/a');
printf("Server RSS peak:   %s\n", $peakAfter > 0 ? formatBytes($peakAfter) : 'n/a');
printf("Bytes per key:     %s\n", $rssAfter > 0 && $rssAfter >= $rssBefore ? formatBytes(intdiv($rssAfter - $rssBefore, KEYS)) : 'n/a');

fclose($socket);

function formatBytes(int $bytes): string
{
    return number_format($bytes) . ' B (' . number_format($bytes / 1024 / 1024, 2) . ' MiB)';
}
