<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\Storage\Store;
use PhpMiniCache\Transaction\TransactionManager;

final readonly class DiscardCommand implements CommandHandler
{
    public function __construct(
        private TransactionManager $transactions,
    ) {
    }

    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if (!$this->transactions->isActive($connection)) {
            return RespValue::error('ERR DISCARD without MULTI');
        }

        $this->transactions->discard($connection);

        return RespValue::simpleString('OK');
    }
}
