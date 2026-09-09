#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: does a message published on one connection actually reach
 * a different connection subscribed to that channel, and only that
 * channel? Needs two connections doing different things at the same
 * time, which a single-threaded script can't do on its own - so this
 * forks a subscriber child and publishes from the parent, the same
 * pcntl_fork() primitive the rest of this project's forked-client tools
 * use.
 */

require __DIR__ . '/bootstrap.php';

$subscriberPid = pcntl_fork();

if ($subscriberPid === -1) {
    fwrite(STDERR, "pcntl_fork() failed\n");

    exit(1);
}

if ($subscriberPid === 0) {
    // Child: subscribe and print whatever arrives, until the parent kills it.
    $subscriber = exampleClient();
    printf("[subscriber] subscribed, now watching %d channel(s)\n", $subscriber->subscribe('news'));

    while (true) { // @phpstan-ignore while.alwaysTrue
        $message = $subscriber->nextMessage(timeoutSeconds: 5.0);

        if ($message !== null) {
            printf("[subscriber] message on %s: %s\n", $message->channel, $message->payload);
        }
    }
}

// Parent: give the child a moment to actually subscribe before publishing.
usleep(200_000);

$publisher = exampleClient();

foreach (['first message', 'second message'] as $text) {
    printf("[publisher] PUBLISH news \"%s\"\n", $text);
    printf("[publisher] -> delivered to %d subscriber(s)\n", $publisher->publish('news', $text));
    usleep(200_000);
}

$publisher->close();

// Let the subscriber's last printf actually reach the terminal before it dies.
usleep(200_000);
posix_kill($subscriberPid, SIGTERM);
pcntl_waitpid($subscriberPid, $status);
