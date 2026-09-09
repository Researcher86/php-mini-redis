<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandDispatcher;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\Storage\Store;
use App\Transaction\TransactionManager;

final readonly class ExecCommand implements CommandHandler
{
    public function __construct(
        private TransactionManager $transactions,
        private CommandDispatcher $dispatcher,
    ) {
    }

    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if (!$this->transactions->isActive($connection)) {
            return RespValue::error('ERR EXEC without MULTI');
        }

        $results = array_map(
            fn (Command $queued): RespValue => $this->dispatcher->dispatch($queued, $store, $connection),
            $this->transactions->drain($connection),
        );

        return RespValue::array($results);
    }
}
