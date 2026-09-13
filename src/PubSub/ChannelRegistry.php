<?php

declare(strict_types=1);

namespace PhpMiniCache\PubSub;

use PhpMiniCache\Connection\ClientConnection;

/**
 * Tracks which connections are subscribed to which channels.
 *
 * Keyed by `ClientConnection::id()` - the socket's resource id - rather
 * than by the object, so a channel's subscribers are a plain array and a
 * connection can be removed from every channel without a scan for object
 * identity. The price is that entries must be dropped explicitly when a
 * connection goes (`RedisServer::disconnectClient()` does it): those ids
 * are reused by later connections, so a leftover entry does not merely
 * leak, it eventually points at someone else.
 */
final class ChannelRegistry
{
    /** @var array<string, array<int, ClientConnection>> */
    private array $channels = [];

    public function subscribe(string $channel, ClientConnection $connection): void
    {
        $this->channels[$channel][$connection->id()] = $connection;
    }

    /**
     * Removes $connection from every channel, e.g. when it disconnects.
     */
    public function unsubscribeAll(ClientConnection $connection): void
    {
        foreach (array_keys($this->channels) as $channel) {
            unset($this->channels[$channel][$connection->id()]);

            if ($this->channels[$channel] === []) {
                unset($this->channels[$channel]);
            }
        }
    }

    /** @return list<ClientConnection> */
    public function subscribers(string $channel): array
    {
        return array_values($this->channels[$channel] ?? []);
    }

    public function subscriptionCount(ClientConnection $connection): int
    {
        $count = 0;

        foreach ($this->channels as $subscribers) {
            if (isset($subscribers[$connection->id()])) {
                $count++;
            }
        }

        return $count;
    }
}
