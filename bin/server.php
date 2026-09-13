#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Logging\ConsoleLogger;
use PhpMiniCache\Server\RedisServer;
use PhpMiniCache\Server\ServerConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6380);

$logger = new ConsoleLogger();
$server = new RedisServer(new ServerConfig(host: $host, port: $port), logger: $logger);

$logger->info(sprintf('Listening on %s', $server->localAddress()));

// The event loop accepts connections as they arrive, so this process never
// blocks waiting on one particular client; everything each connection then
// sends is read, executed and answered from inside the same loop. This
// callback is the one hook into that - it runs per accepted connection,
// before the client has sent anything.
$server->run(function (ClientConnection $connection) use ($server, $logger): void {
    $logger->info(sprintf('Client connected. Total: %d', $server->connectedClientCount()));
});
