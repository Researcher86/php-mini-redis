#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Load: Pub/Sub fan-out. Forks $subscribers child connections, each
 * subscribed to one shared channel, then publishes a run of messages from
 * the parent and measures how long the fan-out takes - total messages
 * delivered and delivery rate, showing how the server scales as the
 * subscriber count grows.
 *
 * Requires a running server (make run-server) in another terminal.
 */

require dirname(__DIR__) . '/examples/bootstrap.php';

const SUBSCRIBERS = 20;
const MESSAGES = 50;

/** @var list<int> $subscriberPids */
$subscriberPids = [];

for ($i = 0; $i < SUBSCRIBERS; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        fwrite(STDERR, "pcntl_fork() failed\n");
        exit(1);
    }

    if ($pid === 0) {
        // Child: subscribe and drain everything that arrives until killed.
        $socket = exampleConnect();
        exampleCommand($socket, ['SUBSCRIBE', 'news']);

        while (true) {
            $message = exampleReceive($socket);

            if (isset($message->value[2]) && $message->value[2]->value === '__done__') {
                fclose($socket);
                exit(0);
            }
        }
    }

    $subscriberPids[] = $pid;
}

// Give every child a moment to actually subscribe before publishing.
usleep(300_000);

$publisher = exampleConnect();

$start = microtime(true);

for ($i = 0; $i < MESSAGES; $i++) {
    exampleCommand($publisher, ['PUBLISH', 'news', "msg-$i"]);
}

$totalDelivered = SUBSCRIBERS * MESSAGES;
$seconds = microtime(true) - $start;

printf("Subscribers:  %d\n", SUBSCRIBERS);
printf("Messages:     %d\n", MESSAGES);
printf("Fan-out total %d deliveries in %.3fs\n", $totalDelivered, $seconds);
printf("Deliveries/s: %.0f\n", $totalDelivered / max($seconds, 1e-9));

// Signal subscribers to exit, then reap them.
exampleCommand($publisher, ['PUBLISH', 'news', '__done__']);
fclose($publisher);

usleep(200_000);

foreach ($subscriberPids as $pid) {
    posix_kill($pid, SIGTERM);
}

foreach ($subscriberPids as $pid) {
    pcntl_waitpid($pid, $status);
}
