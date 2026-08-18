<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\LogRegistry;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Support\Clock\Clock;
use Quiote\Support\Clock\FrozenClock;

/** Accepts everything, so what a logger decides to emit is what gets recorded. */
final class CategoryLoggerCapturingSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $captured = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return true;
    }

    public function emit(LogEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function flush(): void
    {
    }
}

/**
 * A log event's timestamp goes through the clock seam rather than a direct
 * microtime(true) call, so a test (or a replay engine) can pin it.
 */
final class CategoryLoggerTest extends TestCase
{
    #[Before]
    #[After]
    public function resetLogging(): void
    {
        LogRegistry::reset();
        Clock::useClock(null);
    }

    public function testEventTimestampComesFromTheInjectedClock(): void
    {
        $sink = new CategoryLoggerCapturingSink();
        Log::addSink($sink);
        Clock::useClock(new FrozenClock(1_700_000_000.5));

        Log::create('Quiote.Test.CategoryLogger')->info('hello');

        $this->assertCount(1, $sink->captured);
        $this->assertSame(1_700_000_000.5, $sink->captured[0]->timestamp);
    }

    /** The real, unmocked path: a SystemClock-derived timestamp is a plausible "now". */
    public function testEventTimestampDefaultsToTheRealClock(): void
    {
        $sink = new CategoryLoggerCapturingSink();
        Log::addSink($sink);

        $before = microtime(true);
        Log::create('Quiote.Test.CategoryLogger')->info('hello');
        $after = microtime(true);

        $this->assertCount(1, $sink->captured);
        $this->assertGreaterThanOrEqual($before, $sink->captured[0]->timestamp);
        $this->assertLessThanOrEqual($after, $sink->captured[0]->timestamp);
    }
}
