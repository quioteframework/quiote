<?php

namespace Quiote\ExceptionNotifier;

use Quiote\Support\Clock\ClockInterface;
use Throwable;

/**
 * Per-process throttle: suppresses re-notifying the same exception class +
 * message within `exception_notifier.throttle_seconds` of the last
 * notification, so a storm of identical errors sends one notification instead
 * of one per request. Registered as a container singleton (see
 * {@see ExceptionNotifierPlugin}) so state persists for the worker's
 * lifetime, the same way {@see \Quiote\Http\Client\HttpClientFactory} does.
 */
final class ExceptionNotificationThrottle
{
    /** @var array<string, float> last-notified wall-clock time, keyed by exception class+message */
    private array $lastNotifiedAt = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $windowSeconds,
    ) {
    }

    /**
     * Whether a notification for $exception should be suppressed because one
     * for the same class+message already went out within the window. A
     * window of zero or less disables throttling entirely. Recording the
     * notification happens here, not via a separate call, so a caller cannot
     * forget to mark it delivered.
     */
    public function shouldSuppress(Throwable $exception): bool
    {
        if ($this->windowSeconds <= 0) {
            return false;
        }

        $key = $this->keyFor($exception);
        $now = $this->clock->microtime();
        $last = $this->lastNotifiedAt[$key] ?? null;

        if ($last !== null && ($now - $last) < $this->windowSeconds) {
            return true;
        }

        $this->lastNotifiedAt[$key] = $now;
        return false;
    }

    /** Test isolation / explicit reset: forgets every recorded notification. */
    public function reset(): void
    {
        $this->lastNotifiedAt = [];
    }

    private function keyFor(Throwable $exception): string
    {
        return hash('xxh3', $exception::class . '|' . $exception->getMessage());
    }
}
