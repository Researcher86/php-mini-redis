<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\Storage\Store;
use App\Transaction\TransactionManager;

final readonly class MultiCommand implements CommandHandler
{
    public function __construct(private TransactionManager $transactions)
    {
    }

    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if ($this->transactions->isActive($connection)) {
            return RespValue::error('ERR MULTI calls can not be nested');
        }

        $this->transactions->begin($connection);

        return RespValue::simpleString('OK');
    }
}
