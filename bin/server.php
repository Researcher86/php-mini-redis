#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Server\RedisServer;
use App\Server\ServerConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6380);

$server = new RedisServer(new ServerConfig(host: $host, port: $port));

fwrite(STDOUT, sprintf("Listening on %s\n", $server->localAddress()));

// Phase 2: a blocking accept loop. Connections are accepted and tracked as
// ClientConnection instances, but nothing is read from them yet - that
// arrives in later phases.
while (true) { // @phpstan-ignore while.alwaysTrue
    $server->acceptClient();
    fwrite(STDOUT, sprintf("Client connected. Total: %d\n", $server->connectedClientCount()));
}
