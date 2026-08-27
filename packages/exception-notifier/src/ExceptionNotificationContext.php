<?php

namespace Quiote\ExceptionNotifier;

/**
 * Snapshot of the request/exception metadata a {@see NotifierChannelInterface}
 * needs to render a notification, built once per notified exception by
 * {@see ExceptionNotificationListener} so every channel renders the same facts.
 */
final readonly class ExceptionNotificationContext
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public int $status,
        public string $requestMethod,
        public string $requestUri,
        public ?string $correlationId,
        public float $timestamp,
        public array $extra = [],
    ) {
    }
}
