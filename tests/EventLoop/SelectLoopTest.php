<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\EventLoop\SelectLoop;
use PHPUnit\Framework\TestCase;

final class SelectLoopTest extends TestCase
{
    public function testTickReturnsZeroWhenNothingIsRegistered(): void
    {
        $loop = new SelectLoop();

        self::assertSame(0, $loop->tick(0.05));
    }

    public function testTickReturnsZeroOnTimeout(): void
    {
        // Keep $b alive: an unreferenced peer gets garbage-collected and
        // closed, which would make $a spuriously readable (EOF).
        [$a, $b] = $this->pairOfSockets();
        $loop = new SelectLoop();

        $loop->onReadable($a, function (): void {
            self::fail('Should not be called: nothing was written.');
        });

        self::assertSame(0, $loop->tick(0.05));
    }

    public function testOnReadableFiresWhenDataArrives(): void
    {
        [$a, $b] = $this->pairOfSockets();
        $loop = new SelectLoop();

        $received = null;
        $loop->onReadable($a, function ($stream) use (&$received): void {
            $received = fread($stream, 1024);
        });

        fwrite($b, 'PING');
        self::assertSame(1, $loop->tick(1));
        self::assertSame('PING', $received);
    }

    public function testOnWritableFiresForAWritableStream(): void
    {
        [$a, $b] = $this->pairOfSockets();
        $loop = new SelectLoop();

        $fired = false;
        $loop->onWritable($a, function () use (&$fired): void {
            $fired = true;
        });

        self::assertSame(1, $loop->tick(1));
        self::assertTrue($fired);
    }

    public function testRemoveReadableStopsDispatching(): void
    {
        [$a, $b] = $this->pairOfSockets();
        $loop = new SelectLoop();

        $loop->onReadable($a, function (): void {
            self::fail('Should not be called: the listener was removed.');
        });
        $loop->removeReadable($a);

        fwrite($b, 'PING');
        self::assertSame(0, $loop->tick(0.05));
    }

    public function testStopEndsRun(): void
    {
        [$a, $b] = $this->pairOfSockets();
        $loop = new SelectLoop();

        $ticks = 0;
        $loop->onReadable($a, function ($stream) use (&$ticks, $loop): void {
            fread($stream, 1024);
            $ticks++;
            $loop->stop();
        });

        fwrite($b, 'PING');
        $loop->run();

        self::assertSame(1, $ticks);
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
