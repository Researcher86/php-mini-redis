<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\Connection;

use PhpMiniCache\Connection\ClientConnection;
use PhpMiniCache\Connection\ConnectionManager;
use PhpMiniCache\Connection\ConnectionState;
use PHPUnit\Framework\TestCase;

final class ConnectionManagerTest extends TestCase
{
    public function testAddAndCount(): void
    {
        $manager = new ConnectionManager();
        [$a, $b] = $this->twoConnections();

        $manager->add($a);
        $manager->add($b);

        self::assertSame(2, $manager->count());
        self::assertCount(2, $manager->all());

        $manager->closeAll();
    }

    public function testRemove(): void
    {
        $manager = new ConnectionManager();
        [$a, $b] = $this->twoConnections();

        $manager->add($a);
        $manager->add($b);
        $manager->remove($a);

        self::assertSame(1, $manager->count());
        self::assertSame([$b->id() => $b], $manager->all());

        $manager->closeAll();
    }

    public function testCloseAllClosesEveryConnectionAndEmptiesTheManager(): void
    {
        $manager = new ConnectionManager();
        [$a, $b] = $this->twoConnections();

        $manager->add($a);
        $manager->add($b);

        $manager->closeAll();

        self::assertSame(0, $manager->count());
        self::assertSame(ConnectionState::Closed, $a->state());
        self::assertSame(ConnectionState::Closed, $b->state());
    }

    /**
     * @return array{0: ClientConnection, 1: ClientConnection}
     */
    private function twoConnections(): array
    {
        $pairA = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pairB = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pairA);
        self::assertIsArray($pairB);

        return [new ClientConnection($pairA[0]), new ClientConnection($pairB[0])];
    }
}
