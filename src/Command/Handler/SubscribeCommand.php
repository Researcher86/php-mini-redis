<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\PubSub\ChannelRegistry;
use PhpMiniCache\Storage\Store;

final readonly class SubscribeCommand implements CommandHandler
{
    public function __construct(
        private ChannelRegistry $channels,
    ) {
    }

    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if (count($command->arguments) !== 1) {
            return RespValue::error("ERR wrong number of arguments for 'subscribe' command");
        }

        [$channel] = $command->arguments;
        $this->channels->subscribe($channel, $connection);

        return RespValue::array([
            RespValue::bulkString('subscribe'),
            RespValue::bulkString($channel),
            RespValue::integer($this->channels->subscriptionCount($connection)),
        ]);
    }
}
