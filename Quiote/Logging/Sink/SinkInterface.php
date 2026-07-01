<?php

namespace Quiote\Logging\Sink;

use Quiote\Logging\Level;
use Quiote\Logging\LogEvent;

/**
 * A destination for log events. Each sink decides, per (level, category),
 * whether it will accept an event, and how to render it.
 */
interface SinkInterface
{
    /**
     * Whether this sink will emit an event at $level for $category. Kept cheap
     * (called on the hot path via CategoryLogger::isEnabled()).
     */
    public function isEnabled(Level $level, string $category): bool;

    /**
     * Write the event to the destination. Only called when isEnabled() is true.
     */
    public function emit(LogEvent $event): void;

    /**
     * Flush any buffered output (end of request / worker reset).
     */
    public function flush(): void;
}
