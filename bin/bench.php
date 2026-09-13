#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpMiniCache\Sdk\RedisClient;
use PhpMiniCache\Sdk\RedisClientException;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * One client's share of the benchmark: a real connection, run
 * synchronously (send, wait for the reply, send the next) - $requests
 * request/reply round trips, each one timed. Writes its raw per-request
 * latencies to $resultFile as JSON, since a forked child cannot return a
 * value to the parent any other way.
 *
 * @param list<string> $command
 */
function runBenchClient(string $host, int $port, array $command, int $requests, string $resultFile): void
{
    $client = new RedisClient($host, $port);
    $latenciesMs = [];

    try {
        for ($i = 0; $i < $requests; $i++) {
            $start = microtime(true);
            $client->command(...$command);
            $latenciesMs[] = (microtime(true) - $start) * 1000;
        }
    } catch (RedisClientException $exception) {
        fwrite(STDERR, sprintf("Client gave up after %d requests: %s\n", count($latenciesMs), $exception->getMessage()));
    } finally {
        $client->close();
    }

    file_put_contents($resultFile, json_encode(['requests' => count($latenciesMs), 'latenciesMs' => $latenciesMs]));
}

/**
 * The value at $p through the sorted samples - nearest-rank, so p50 of ten
 * samples is a sample that was actually measured rather than an average of
 * two. min() keeps p100 inside the array.
 *
 * @param list<float> $sortedLatenciesMs
 */
function percentile(array $sortedLatenciesMs, float $p): float
{
    $count = count($sortedLatenciesMs);

    if ($count === 0) {
        return 0.0;
    }

    return $sortedLatenciesMs[min($count - 1, (int) floor($p * $count))];
}

$options = getopt('', ['clients:', 'requests:', 'command:', 'host:', 'port:']);
$clients = (int) ($options['clients'] ?? 10);
$requestsPerClient = (int) ($options['requests'] ?? 1000);
$commandName = strtoupper((string) ($options['command'] ?? 'PING'));
$host = (string) ($options['host'] ?? (getenv('REDIS_HOST') ?: '127.0.0.1'));
$port = (int) ($options['port'] ?? (getenv('REDIS_PORT') ?: 6380));

$command = match ($commandName) {
    'PING' => ['PING'],
    'SET' => ['SET', 'bench:key', 'value'],
    'GET' => ['GET', 'bench:key'],
    'INCR' => ['INCR', 'bench:counter'],
    default => null,
};

if ($command === null) {
    fwrite(STDERR, sprintf("Unknown --command=%s (use PING, SET, GET or INCR)\n", $commandName));

    exit(1);
}

$resultsDir = sys_get_temp_dir() . '/mini-redis-bench-' . getmypid();

if (!mkdir($resultsDir) && !is_dir($resultsDir)) {
    fwrite(STDERR, sprintf("Could not create %s\n", $resultsDir));

    exit(1);
}

$start = microtime(true);
$pids = [];

for ($i = 0; $i < $clients; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, "pcntl_fork() failed\n");

        exit(1);
    }

    if ($pid === 0) {
        runBenchClient($host, $port, $command, $requestsPerClient, $resultsDir . '/' . getmypid() . '.json');

        exit(0);
    }

    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$elapsedSeconds = microtime(true) - $start;

$latenciesMs = [];
$totalRequests = 0;

foreach (glob($resultsDir . '/*.json') ?: [] as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    $latenciesMs = [...$latenciesMs, ...$data['latenciesMs']];
    $totalRequests += $data['requests'];
    unlink($file);
}

rmdir($resultsDir);
sort($latenciesMs);

printf("Command:            %s\n", $commandName);
printf("Clients:             %d\n", $clients);
printf("Requests per client: %d\n", $requestsPerClient);
printf("Total requests:      %d\n", $totalRequests);
printf("Elapsed:             %.2fs\n", $elapsedSeconds);
printf("Requests/sec:        %.0f\n", $elapsedSeconds > 0 ? $totalRequests / $elapsedSeconds : 0);
printf("Latency p50:         %.3f ms\n", percentile($latenciesMs, 0.50));
printf("Latency p95:         %.3f ms\n", percentile($latenciesMs, 0.95));
printf("Latency p99:         %.3f ms\n", percentile($latenciesMs, 0.99));
