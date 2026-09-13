<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\PubSub;

use PhpMiniCache\PubSub\ChannelRegistry;
use PhpMiniCache\Tests\Support\CreatesTestConnections;
use PHPUnit\Framework\TestCase;

final class ChannelRegistryTest extends TestCase
{
    use CreatesTestConnections;

    public function testSubscribersReturnsEveryoneSubscribedToAChannel(): void
    {
        $registry = new ChannelRegistry();
        $a = $this->createConnection();
        $b = $this->createConnection();

        $registry->subscribe('news', $a);
        $registry->subscribe('news', $b);

        self::assertSame([$a, $b], $registry->subscribers('news'));
    }

    public function testSubscribersReturnsAnEmptyListForAnUnknownChannel(): void
    {
        $registry = new ChannelRegistry();

        self::assertSame([], $registry->subscribers('missing'));
    }

    public function testSubscribingTwiceToTheSameChannelDoesNotDuplicateTheSubscriber(): void
    {
        $registry = new ChannelRegistry();
        $connection = $this->createConnection();

        $registry->subscribe('news', $connection);
        $registry->subscribe('news', $connection);

        self::assertSame([$connection], $registry->subscribers('news'));
    }

    public function testSubscriptionCountTracksHowManyChannelsAConnectionJoined(): void
    {
        $registry = new ChannelRegistry();
        $connection = $this->createConnection();

        self::assertSame(0, $registry->subscriptionCount($connection));

        $registry->subscribe('news', $connection);
        $registry->subscribe('sports', $connection);

        self::assertSame(2, $registry->subscriptionCount($connection));
    }

    public function testUnsubscribeAllRemovesAConnectionFromEveryChannel(): void
    {
        $registry = new ChannelRegistry();
        $a = $this->createConnection();
        $b = $this->createConnection();

        $registry->subscribe('news', $a);
        $registry->subscribe('news', $b);
        $registry->subscribe('sports', $a);

        $registry->unsubscribeAll($a);

        self::assertSame([$b], $registry->subscribers('news'));
        self::assertSame([], $registry->subscribers('sports'));
        self::assertSame(0, $registry->subscriptionCount($a));
    }
}
