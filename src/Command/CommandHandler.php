<?php

declare(strict_types=1);

namespace App\Command;

use App\Protocol\RespValue;
use App\Storage\Store;

interface CommandHandler
{
    public function handle(Command $command, Store $store): RespValue;
}
