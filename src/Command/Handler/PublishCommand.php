<?php

declare(strict_types=1);

namespace PhpMiniCache\Command\Handler;

use PhpMiniCache\Command\Command;
use PhpMiniCache\Command\CommandHandler;
use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Protocol\RespEncoder;
use PhpMiniCache\Protocol\RespValue;
use PhpMiniCache\PubSub\ChannelRegistry;
use PhpMiniCache\Storage\Store;

final readonly class PublishCommand implements CommandHandler
{
    /**
     * @param callable(ClientConnection, string): void $deliver Sends already-encoded bytes to a subscriber's connection.
     */
    public function __construct(
        private ChannelRegistry $channels,
        private mixed $deliver,
        private RespEncoder $encoder = new RespEncoder(),
    ) {
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
