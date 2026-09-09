<?php

declare(strict_types=1);

use App\Sdk\RedisClient;
use App\Sdk\RedisClientException;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Shared by every script under examples/: each one answers one question
 * against a real, already-running server (start it first with
 * `make run-server`), so this just builds the client they all use - and
 * turns "nothing is listening on 6380" into the sentence that actually
 * helps, instead of a stack trace from the middle of the demo.
 */
function exampleClient(float $timeoutSeconds = 5.0): RedisClient
{
    $client = new RedisClient(
        getenv('REDIS_HOST') ?: '127.0.0.1',
        (int) (getenv('REDIS_PORT') ?: 6380),
        $timeoutSeconds,
    );

    try {
        $client->ping();
    } catch (RedisClientException $exception) {
        fwrite(STDERR, $exception->getMessage() . " - is `make run-server` running?\n");

        exit(1);
    }

    return $client;
}
