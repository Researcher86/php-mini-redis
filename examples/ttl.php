#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One question: does a key set with EX actually disappear once its TTL
 * has passed, and does that happen without ever reading it again in
 * between (Phase 17's active expiration, not just Phase 16's lazy check)?
 */

require __DIR__ . '/bootstrap.php';

$socket = exampleConnect();

echo "SET session abc EX 2\n";
echo '-> ' . exampleCommand($socket, ['SET', 'session', 'abc', 'EX', '2'])->value . "\n\n";

echo "GET session (immediately)\n";
echo '-> ' . exampleCommand($socket, ['GET', 'session'])->value . "\n\n";

echo "Waiting 3 seconds for the TTL to pass...\n\n";
sleep(3);

echo "GET session (after the TTL)\n";
$value = exampleCommand($socket, ['GET', 'session'])->value;
echo '-> ' . ($value === null ? '(nil)' : $value) . "\n";

fclose($socket);
