<?php

namespace Agavi\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * A PSR-3 logger bound to a single category. The 8 level methods come from
 * {@see LoggerTrait} and funnel into {@see log()}; {@see isEnabled()} is the
 * cheap hot-path guard for callers to skip expensive message construction.
 *
 * The category threshold is resolved once (via {@see LogRegistry}) and cached on
 * the instance — safe because logging config is immutable for the worker lifetime.
 */
final class CategoryLogger implements LoggerInterface
{
    use LoggerTrait;

    private ?Level $threshold = null;

    public function __construct(private readonly string $category) {}

    public function category(): string
    {
        return $this->category;
    }

    private function threshold(): Level
    {
        return $this->threshold ??= LogRegistry::resolveLevel($this->category);
    }

    /**
     * Whether an event at $level for this category would be emitted by at least
     * one sink: passes the category threshold AND some sink accepts it. Allocates
     * nothing; safe to call per request on the hot path.
     */
    public function isEnabled(Level $level): bool
    {
        if (!$level->passes($this->threshold())) {
            return false;
        }
        foreach (LogRegistry::sinks() as $sink) {
            if ($sink->isEnabled($level, $this->category)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $level A PSR-3 level string or a {@see Level}.
     * @param array<string,mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $lvl = $level instanceof Level ? $level : Level::fromPsr((string) $level);

        // Resolve enabled sinks first; skip all work (event construction,
        // scope merge, interpolation) when nothing will consume the event.
        if (!$lvl->passes($this->threshold())) {
            return;
        }
        $sinks = [];
        foreach (LogRegistry::sinks() as $sink) {
            if ($sink->isEnabled($lvl, $this->category)) {
                $sinks[] = $sink;
            }
        }
        if ($sinks === []) {
            return;
        }

        $exception = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
        }

        $event = new LogEvent(
            timestamp: microtime(true),
            level: $lvl,
            category: $this->category,
            messageTemplate: (string) $message,
            properties: $context,
            scope: LogContext::current(),
            exception: $exception,
        );

        foreach ($sinks as $sink) {
            $sink->emit($event);
        }
    }
}
