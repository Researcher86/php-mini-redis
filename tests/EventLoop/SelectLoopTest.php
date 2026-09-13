<?php

declare(strict_types=1);

namespace PhpMiniCache\Tests\EventLoop;

use PhpMiniCache\EventLoop\SelectLoop;
use PhpMiniCache\Tests\Support\FakeClock;
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

    public function testMetricsCountIterations(): void
    {
        $loop = new SelectLoop();

        $loop->tick(0.01);
        $loop->tick(0.01);
        $loop->tick(0.01);

        self::assertSame(3, $loop->metrics()->iterations());
    }

    public function testMetricsRecordBusyTimeAndLagFromCallbackDispatch(): void
    {
        [$a, $b] = $this->pairOfSockets();
        $loop = new SelectLoop();

        $loop->onWritable($a, function (): void {
            // A deliberately slow callback: the loop is held away from
            // select() for this long, which is exactly the "lag" the metric
            // is meant to surface.
            usleep(50_000);
        });

        self::assertSame(1, $loop->tick(1));
        self::assertGreaterThanOrEqual(0.05, $loop->metrics()->maxLagSeconds());
        self::assertGreaterThanOrEqual(0.05, $loop->metrics()->busySeconds());
        self::assertSame(1, $loop->metrics()->iterations());
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

    public function testEveryFiresARepeatingTimerEvenWithoutAnyStreams(): void
    {
        $clock = new FakeClock(1000.0);
        $loop = new SelectLoop($clock);

        $fired = 0;
        $loop->every(0.01, function () use (&$fired): void {
            $fired++;
        });

        $clock->advance(0.01);
        self::assertSame(0, $loop->tick(0));
        self::assertSame(1, $fired);

        $clock->advance(0.01);
        $loop->tick(0);
        self::assertSame(2, $fired);
    }

    public function testATimerFiresAlongsideStreamActivity(): void
    {
        [$a, $b] = $this->pairOfSockets();
        $clock = new FakeClock(1000.0);
        $loop = new SelectLoop($clock);

        $timerFired = false;
        $loop->after(0.01, function () use (&$timerFired): void {
            $timerFired = true;
        });

        $received = null;
        $loop->onReadable($a, function ($stream) use (&$received): void {
            $received = fread($stream, 1024);
        });

        fwrite($b, 'PING');
        $clock->advance(0.01);
        $loop->tick(1);

        self::assertSame('PING', $received);
        self::assertTrue($timerFired);
    }

    public function testAStreamClosedBehindTheLoopIsForgottenInsteadOfSelectedOn(): void
    {
        [$a, $b] = $this->pairOfSockets();
        $loop = new SelectLoop();

        $loop->onReadable($a, function (): void {
            self::fail('Should not be called: the stream was closed.');
        });
        $loop->onWritable($a, function (): void {
            self::fail('Should not be called: the stream was closed.');
        });

        // Closed by its owner without deregistering - stream_select() would
        // raise a TypeError on it, taking the whole process down.
        fclose($a);

        self::assertSame(0, $loop->tick(0.01));
    }

    public function testAStreamClosedByAnEarlierCallbackIsNotDispatchedInTheSamePass(): void
    {
        [$a, $aPeer] = $this->pairOfSockets();
        [$b, $bPeer] = $this->pairOfSockets();
        $loop = new SelectLoop();

        // Both are readable in the same pass, and the first callback closes
        // the second's stream - the way a shutdown, or a PUBLISH dropping a
        // broken subscriber, closes connections other than the one being
        // serviced.
        $loop->onReadable($a, function ($stream) use ($b): void {
            fread($stream, 1024);
            fclose($b);
        });
        $loop->onReadable($b, function (): void {
            self::fail('Should not be called: the stream was closed mid-pass.');
        });

        fwrite($aPeer, 'PING');
        fwrite($bPeer, 'PING');

        self::assertSame(2, $loop->tick(1));
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
