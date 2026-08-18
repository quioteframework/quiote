<?php

declare(strict_types=1);

namespace Quiote\Support\Clock;

/**
 * The real clock: {@see ClockInterface::now()}/{@see ClockInterface::unixTimestamp()}/
 * {@see ClockInterface::microtime()} answer from the system wall clock exactly
 * like `new DateTimeImmutable()`/`time()`/`microtime(true)` always did, and
 * {@see ClockInterface::monotonic()} from `hrtime(true)`. This is what the
 * container binds {@see ClockInterface} to by default; nothing here is
 * mockable, which is the point -- tests reach for {@see FrozenClock} or
 * {@see OffsetClock} instead of stubbing this class.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    public function unixTimestamp(): int
    {
        return time();
    }

    public function microtime(): float
    {
        return microtime(true);
    }

    public function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
