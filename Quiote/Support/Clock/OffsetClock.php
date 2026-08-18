<?php

declare(strict_types=1);

namespace Quiote\Support\Clock;

/**
 * A clock that ticks in real time but reports a fixed offset from another
 * clock -- "the client's clock is ten minutes fast", "this node's clock has
 * drifted 30 seconds behind the cluster". Unlike {@see FrozenClock}, time
 * still passes between two reads; only the offset is under test control.
 *
 * The offset is applied to every reading, monotonic included: a constant
 * shift cancels out of any duration measured as the difference of two
 * readings, so offsetting it too keeps {@see monotonic()} internally
 * consistent with {@see microtime()} rather than needing a separate flag for
 * "offset wall-clock only".
 */
final class OffsetClock implements ClockInterface
{
    public function __construct(
        private readonly ClockInterface $inner,
        private float $offsetSeconds = 0.0,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('@%.6F', $this->microtime()));
    }

    public function unixTimestamp(): int
    {
        return (int) $this->microtime();
    }

    public function microtime(): float
    {
        return $this->inner->microtime() + $this->offsetSeconds;
    }

    public function monotonic(): float
    {
        return $this->inner->monotonic() + $this->offsetSeconds;
    }

    public function setOffset(float $offsetSeconds): void
    {
        $this->offsetSeconds = $offsetSeconds;
    }

    public function offset(): float
    {
        return $this->offsetSeconds;
    }
}
