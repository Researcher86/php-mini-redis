<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespValue;
use App\PubSub\ChannelRegistry;
use App\Storage\Store;

final class SubscribeCommand implements CommandHandler
{
    public function __construct(private readonly ChannelRegistry $channels)
    {
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
