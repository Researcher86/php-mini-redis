<?php

declare(strict_types=1);

namespace App\Tests\Connection;

use App\Connection\ClientConnection;
use App\Connection\ConnectionState;
use PHPUnit\Framework\TestCase;

final class ClientConnectionTest extends TestCase
{
    public function testNewConnectionStartsInNewState(): void
    {
        $connection = new ClientConnection($this->pairOfSockets()[0]);

        self::assertSame(ConnectionState::New, $connection->state());

        $connection->close();
    }

    public function testSetStateUpdatesStateAndLastActivity(): void
    {
        $connection = new ClientConnection($this->pairOfSockets()[0]);
        $before = $connection->lastActivityAt();

        usleep(1000);
        $connection->setState(ConnectionState::Reading);

        self::assertSame(ConnectionState::Reading, $connection->state());
        self::assertGreaterThan($before, $connection->lastActivityAt());

        $connection->close();
    }

    public function testReadAndWriteBuffersAccumulateBytes(): void
    {
        $connection = new ClientConnection($this->pairOfSockets()[0]);

        $connection->appendToReadBuffer('SET ');
        $connection->appendToReadBuffer('foo bar');
        self::assertSame('SET foo bar', $connection->readBuffer()->contents());

        $connection->appendToWriteBuffer('+OK');
        $connection->appendToWriteBuffer("\r\n");
        self::assertSame("+OK\r\n", $connection->writeBuffer()->contents());

        $connection->close();
    }

    public function testCloseMarksConnectionAsClosed(): void
    {
        $connection = new ClientConnection($this->pairOfSockets()[0]);

        $connection->close();

        self::assertSame(ConnectionState::Closed, $connection->state());
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function pairOfSockets(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);

        return $pair;
    }
}
