#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Protocol\RespEncoder;
use App\Protocol\RespParser;
use App\Protocol\RespValue;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * One client's share of the benchmark: a real connection, run
 * synchronously (send, wait for the reply, send the next) - $requests
 * request/reply round trips, each one timed. Writes its raw per-request
 * latencies to $resultFile as JSON, since a forked child cannot return a
 * value to the parent any other way.
 */
function runBenchClient(string $host, int $port, string $payload, int $requests, string $resultFile): void
{
    $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, 5);

    if ($socket === false) {
        fwrite(STDERR, sprintf("Client could not connect: %s (%d)\n", $errstr, $errno));
        exit(1);
    }

    $parser = new RespParser();
    $latenciesMs = [];

    for ($i = 0; $i < $requests; $i++) {
        $start = microtime(true);
        fwrite($socket, $payload);

        $buffer = '';

        while ($parser->parse($buffer) === null) {
            $chunk = fread($socket, 65536);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        $latenciesMs[] = (microtime(true) - $start) * 1000;
    }

    fclose($socket);
    file_put_contents($resultFile, json_encode(['requests' => $requests, 'latenciesMs' => $latenciesMs]));
}

/** @param list<float> $sortedLatenciesMs */
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
    'PING' => RespValue::array([RespValue::bulkString('PING')]),
    'SET' => RespValue::array([RespValue::bulkString('SET'), RespValue::bulkString('bench:key'), RespValue::bulkString('value')]),
    'GET' => RespValue::array([RespValue::bulkString('GET'), RespValue::bulkString('bench:key')]),
    'INCR' => RespValue::array([RespValue::bulkString('INCR'), RespValue::bulkString('bench:counter')]),
    default => null,
};

if ($command === null) {
    fwrite(STDERR, sprintf("Unknown --command=%s (use PING, SET, GET or INCR)\n", $commandName));
    exit(1);
}

$payload = (new RespEncoder())->encode($command);

$resultsDir = sys_get_temp_dir() . '/mini-redis-bench-' . getmypid();
mkdir($resultsDir);

$start = microtime(true);
$pids = [];

for ($i = 0; $i < $clients; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, "pcntl_fork() failed\n");
        exit(1);
    }

    if ($pid === 0) {
        runBenchClient($host, $port, $payload, $requestsPerClient, $resultsDir . '/' . getmypid() . '.json');
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
