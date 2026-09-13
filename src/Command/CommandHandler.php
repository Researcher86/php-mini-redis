<?php

declare(strict_types=1);

namespace PhpMiniCache\Command;

use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;

interface CommandHandler
{
    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue;
}
