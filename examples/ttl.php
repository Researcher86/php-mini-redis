#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: does a key set with EX actually disappear once its TTL
 * has passed, and does that happen without ever reading it again in
 * between (Phase 17's active expiration, not just Phase 16's lazy check)?
 */

require __DIR__ . '/bootstrap.php';

$client = exampleClient();

echo "SET session abc EX 2\n";
$client->set('session', 'abc', ttlSeconds: 2);
echo "-> OK\n\n";

echo "GET session (immediately)\n";
echo '-> ' . ($client->get('session') ?? '(nil)') . "\n\n";

echo "Waiting 3 seconds for the TTL to pass...\n\n";
sleep(3);

echo "GET session (after the TTL)\n";
echo '-> ' . ($client->get('session') ?? '(nil)') . "\n";

$client->close();
