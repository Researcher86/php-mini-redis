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
        // Child: subscribe and drain everything that arrives until the
        // publisher says it is done.
        $subscriber = exampleClient(timeoutSeconds: 30.0);
        $subscriber->subscribe('news');

        while (($message = $subscriber->nextMessage()) !== null) {
            if ($message->payload === '__done__') {
                break;
            }
        }

        $subscriber->close();

        exit(0);
    }

    $subscriberPids[] = $pid;
}

// Give every child a moment to actually subscribe before publishing.
usleep(300_000);

$publisher = exampleClient(timeoutSeconds: 30.0);

$start = microtime(true);

for ($i = 0; $i < MESSAGES; $i++) {
    $publisher->publish('news', "msg-$i");
}

$totalDelivered = SUBSCRIBERS * MESSAGES;
$seconds = microtime(true) - $start;

printf("Subscribers:  %d\n", SUBSCRIBERS);
printf("Messages:     %d\n", MESSAGES);
printf("Fan-out total %d deliveries in %.3fs\n", $totalDelivered, $seconds);
printf("Deliveries/s: %.0f\n", $totalDelivered / max($seconds, 1e-9));

// Tell the subscribers to stop, then reap them.
$publisher->publish('news', '__done__');
$publisher->close();

foreach ($subscriberPids as $pid) {
    pcntl_waitpid($pid, $status);
}
