<?php

declare(strict_types=1);

namespace App\Command\Handler;

use App\Command\Command;
use App\Command\CommandHandler;
use App\Connection\ClientConnection;
use App\Protocol\RespEncoder;
use App\Protocol\RespValue;
use App\PubSub\ChannelRegistry;
use App\Storage\Store;

final class PublishCommand implements CommandHandler
{
    private RespEncoder $encoder;

    /**
     * @param callable(ClientConnection, string): void $deliver Sends already-encoded bytes to a subscriber's connection.
     */
    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly mixed $deliver,
    ) {
        $this->encoder = new RespEncoder();
    }

    public function handle(Command $command, Store $store, ClientConnection $connection): RespValue
    {
        if (count($command->arguments) !== 2) {
            return RespValue::error("ERR wrong number of arguments for 'publish' command");
        }

        [$channel, $message] = $command->arguments;
        $subscribers = $this->channels->subscribers($channel);

        $payload = $this->encoder->encode(RespValue::array([
            RespValue::bulkString('message'),
            RespValue::bulkString($channel),
            RespValue::bulkString($message),
        ]));

        foreach ($subscribers as $subscriber) {
            ($this->deliver)($subscriber, $payload);
        }

        return RespValue::integer(count($subscribers));
    }
}
