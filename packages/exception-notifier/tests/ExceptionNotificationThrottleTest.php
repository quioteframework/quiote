<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\ExceptionNotifier\ExceptionNotificationThrottle;
use Quiote\Support\Clock\FrozenClock;

final class ExceptionNotificationThrottleTest extends TestCase
{
    public function testFirstNotificationForAnExceptionIsNeverSuppressed(): void
    {
        $throttle = new ExceptionNotificationThrottle(new FrozenClock(1000.0), 60);
        $this->assertFalse($throttle->shouldSuppress(new RuntimeException('boom')));
    }

    public function testAnImmediateRepeatIsSuppressed(): void
    {
        $throttle = new ExceptionNotificationThrottle(new FrozenClock(1000.0), 60);
        $throttle->shouldSuppress(new RuntimeException('boom'));

        $this->assertTrue($throttle->shouldSuppress(new RuntimeException('boom')));
    }

    public function testARepeatAfterTheWindowElapsesIsNotSuppressed(): void
    {
        $clock = new FrozenClock(1000.0);
        $throttle = new ExceptionNotificationThrottle($clock, 60);
        $throttle->shouldSuppress(new RuntimeException('boom'));

        $clock->advance(61.0);

        $this->assertFalse($throttle->shouldSuppress(new RuntimeException('boom')));
    }

    public function testDifferentMessagesAreThrottledIndependently(): void
    {
        $throttle = new ExceptionNotificationThrottle(new FrozenClock(1000.0), 60);
        $throttle->shouldSuppress(new RuntimeException('boom'));

        $this->assertFalse($throttle->shouldSuppress(new RuntimeException('a different failure')));
    }

    public function testAZeroWindowDisablesThrottlingEntirely(): void
    {
        $throttle = new ExceptionNotificationThrottle(new FrozenClock(1000.0), 0);
        $throttle->shouldSuppress(new RuntimeException('boom'));

        $this->assertFalse($throttle->shouldSuppress(new RuntimeException('boom')));
    }

    public function testResetForgetsEveryRecordedNotification(): void
    {
        $throttle = new ExceptionNotificationThrottle(new FrozenClock(1000.0), 60);
        $throttle->shouldSuppress(new RuntimeException('boom'));

        $throttle->reset();

        $this->assertFalse($throttle->shouldSuppress(new RuntimeException('boom')));
    }
}
