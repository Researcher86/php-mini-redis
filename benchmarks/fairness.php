#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Load: fairness. One question: what does a small client's latency look
 * like while the loop is busy serving somebody else's enormous pipeline?
 *
 * A single-threaded server answers everything in turn, so the honest
 * version of "how fast is it" has to include what one client's work costs
 * every other client. This forks a flood client that pipelines
 * PIPELINE_COMMANDS commands in one go, and times a plain PING round trip
 * against the same server throughout - once before the flood as a
 * baseline, then continuously while it runs.
 *
 * The server's own `eventloop_max_lag_sec` is read before and after: it is
 * the longest single stretch the loop spent dispatching callbacks instead
 * of servicing I/O and timers, which is the same quantity from the
 * server's side.
 *
 * Requires a running server (make run-server) in another terminal.
 */

require dirname(__DIR__) . '/examples/bootstrap.php';

use App\Sdk\RedisClient;

const PIPELINE_COMMANDS = 100_000;
const BASELINE_PROBES = 500;
const PROBE_INTERVAL_MICROSECONDS = 1000;

/**
 * @param list<float> $sorted
 */
function percentile(array $sorted, float $p): float
{
    if ($sorted === []) {
        return 0.0;
    }

    return $sorted[min(count($sorted) - 1, (int) floor($p * count($sorted)))];
}

/** @param list<float> $latenciesMs */
function report(string $label, array $latenciesMs): void
{
    sort($latenciesMs);

    printf(
        "%-24s n=%-6d p50 %7.3f ms   p95 %7.3f ms   p99 %7.3f ms   max %8.3f ms\n",
        $label,
        count($latenciesMs),
        percentile($latenciesMs, 0.50),
        percentile($latenciesMs, 0.95),
        percentile($latenciesMs, 0.99),
        percentile($latenciesMs, 1.0),
    );
}

/**
 * The server's own view of itself, as INFO reports it.
 *
 * @return array<string, float>
 */
function serverCounters(RedisClient $client): array
{
    $counters = [];

    foreach (explode("\r\n", $client->info()) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');

        if (str_starts_with($name, 'eventloop_')) {
            $counters[$name] = (float) $value;
        }
    }

    return $counters;
}

$probe = exampleClient(timeoutSeconds: 30.0);
$before = serverCounters($probe);

// What one PING costs when the server has nothing else to do.
$baseline = [];

for ($i = 0; $i < BASELINE_PROBES; $i++) {
    $start = microtime(true);
    $probe->ping();
    $baseline[] = (microtime(true) - $start) * 1000;
    usleep(PROBE_INTERVAL_MICROSECONDS);
}

$floodPid = pcntl_fork();

if ($floodPid === -1) {
    fwrite(STDERR, "pcntl_fork() failed\n");

    exit(1);
}

if ($floodPid === 0) {
    // Child: one connection, one enormous pipeline, no waiting in between.
    $flood = exampleClient(timeoutSeconds: 120.0);
    $flood->pipeline(array_fill(0, PIPELINE_COMMANDS, ['PING']));
    $flood->close();

    exit(0);
}

// Parent: keep taking the same measurement while that runs.
$underLoad = [];
$start = microtime(true);

while (pcntl_waitpid($floodPid, $status, WNOHANG) === 0) {
    $probeStart = microtime(true);
    $probe->ping();
    $underLoad[] = (microtime(true) - $probeStart) * 1000;
    usleep(PROBE_INTERVAL_MICROSECONDS);
}

$floodSeconds = microtime(true) - $start;
$after = serverCounters($probe);

printf("Flood: %s pipelined commands from one connection, served in %.3fs\n\n", number_format(PIPELINE_COMMANDS), $floodSeconds);
report('PING, server idle', $baseline);
report('PING, during the flood', $underLoad);
$iterations = $after['eventloop_iterations'] - $before['eventloop_iterations'];
$busy = $after['eventloop_busy_sec'] - $before['eventloop_busy_sec'];
$idle = $after['eventloop_idle_sec'] - $before['eventloop_idle_sec'];

printf("\nThe loop, over the same stretch:\n");
printf("  passes:                %s\n", number_format($iterations));
printf("  busy / idle:           %.3fs / %.3fs\n", $busy, $idle);
printf("  mean work per pass:    %.3f ms\n", $iterations > 0 ? ($busy / $iterations) * 1000 : 0.0);
printf("  mean wait per pass:    %.3f ms\n", $iterations > 0 ? ($idle / $iterations) * 1000 : 0.0);
printf("  worst single pass:     %.3f ms (was %.3f ms before)\n", $after['eventloop_max_lag_sec'] * 1000, $before['eventloop_max_lag_sec'] * 1000);

$probe->close();
