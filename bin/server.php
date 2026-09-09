#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Connection\ClientConnection;
use App\Server\RedisServer;
use App\Server\ServerConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6380);

$server = new RedisServer(new ServerConfig(host: $host, port: $port));

fwrite(STDOUT, sprintf("Listening on %s\n", $server->localAddress()));

// Phase 3: the event loop accepts connections as they arrive, so this
// process never blocks waiting on one particular client. Reading and
// writing client data is not implemented yet - that arrives in later
// phases.
$server->run(function (ClientConnection $connection) use ($server): void {
    fwrite(STDOUT, sprintf("Client connected. Total: %d\n", $server->connectedClientCount()));
});
