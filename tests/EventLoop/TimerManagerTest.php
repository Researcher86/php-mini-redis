<?php

declare(strict_types=1);

namespace App\Tests\EventLoop;

use App\EventLoop\TimerManager;
use PHPUnit\Framework\TestCase;

final class TimerManagerTest extends TestCase
{
    public function testIsEmptyInitially(): void
    {
        $timers = new TimerManager();

        self::assertTrue($timers->isEmpty());
        self::assertNull($timers->nextDueIn(1000.0));
    }

    public function testAfterFiresOnceOnceItsDelayHasPassed(): void
    {
        $timers = new TimerManager();
        $fired = 0;
        $timers->after(10.0, function () use (&$fired): void {
            $fired++;
        }, now: 1000.0);

        $timers->tick(1009.0);
        self::assertSame(0, $fired);

        $timers->tick(1010.0);
        self::assertSame(1, $fired);

        // A one-off timer does not fire again.
        $timers->tick(1020.0);
        self::assertSame(1, $fired);
        self::assertTrue($timers->isEmpty());
    }

    public function testEveryFiresRepeatedly(): void
    {
        $timers = new TimerManager();
        $fired = 0;
        $timers->every(10.0, function () use (&$fired): void {
            $fired++;
        }, now: 1000.0);

        $timers->tick(1010.0);
        self::assertSame(1, $fired);

        $timers->tick(1020.0);
        self::assertSame(2, $fired);

        self::assertFalse($timers->isEmpty());
    }

    public function testCancelStopsARepeatingTimer(): void
    {
        $timers = new TimerManager();
        $fired = 0;
        $timer = $timers->every(10.0, function () use (&$fired): void {
            $fired++;
        }, now: 1000.0);

        $timers->tick(1010.0);
        self::assertSame(1, $fired);

        $timer->cancel();
        $timers->tick(1020.0);

        self::assertSame(1, $fired);
        self::assertTrue($timers->isEmpty());
    }

    public function testNextDueInReturnsTheSoonestTimer(): void
    {
        $timers = new TimerManager();
        $timers->after(10.0, static function (): void {
        }, now: 1000.0);
        $timers->after(3.0, static function (): void {
        }, now: 1000.0);

        self::assertSame(3.0, $timers->nextDueIn(1000.0));
        self::assertSame(0.0, $timers->nextDueIn(1005.0));
    }
}
