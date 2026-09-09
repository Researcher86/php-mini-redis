<?php

declare(strict_types=1);

namespace App\PubSub;

use App\Connection\ClientConnection;

/**
 * Tracks which connections are subscribed to which channels.
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
