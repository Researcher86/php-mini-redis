<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandDispatcher;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;
use PhpMiniCache\Transaction\TransactionManager;

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
