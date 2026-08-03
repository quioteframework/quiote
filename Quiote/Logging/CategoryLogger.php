<?php

namespace Quiote\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * A PSR-3 logger bound to a single category. The 8 level methods come from
 * {@see LoggerTrait} and funnel into {@see log()}; {@see isEnabled()} is the
 * cheap hot-path guard for callers to skip expensive message construction.
 * The category threshold is resolved once (via {@see LogRegistry}) and cached on
 * the instance — safe because logging config is immutable for the worker lifetime.
 */
final class CategoryLogger implements LoggerInterface
{
    use LoggerTrait;

    private ?Level $threshold = null;

    /**
     * Memoized isEnabled() result per Level, keyed by Level::value. Logging
     * config (threshold + registered sinks) is immutable for the worker
     * lifetime, same invariant $threshold already relies on -- this avoids
     * reallocating the array_any() closure and re-scanning all sinks on
     * every isEnabled() call, of which there are dozens per request on the
     * happy path (guarding debug() calls).
     * @var array<int, bool>
     */
    private array $enabledCache = [];

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
        return $this->enabledCache[$level->value] ??= $this->computeEnabled($level);
    }

    private function computeEnabled(Level $level): bool
    {
        if (!$level->passes($this->threshold())) {
            return false;
        }
        return array_any(LogRegistry::sinks(), fn($sink) => $sink->isEnabled($level, $this->category));
    }

    /**
     * Emit a debug message built by $build, but only when debug logging is on, and never let
     * building it affect the caller.
     *
     * A diagnostic line frequently has to reach into state that may not be there -- an
     * incident's validator, a report's failed arguments, a session id -- and the traversal
     * can throw. Guarding each such block with its own empty catch hides genuine failures
     * inside diagnostics and reads as though the swallow were about the surrounding logic.
     * This states the rule once: diagnostics never change what the request does, and a
     * diagnostic that could not be assembled says so instead of vanishing.
     *
     * $build is not called at all when debug logging is off, so an expensive message costs
     * nothing in production.
     *
     * @param      callable(): string $build Returns the message; the empty string emits nothing.
     * @since      3.2.0
     */
    public function debugWith(callable $build): void
    {
        if (!$this->isEnabled(Level::Debug)) {
            return;
        }

        try {
            $message = $build();
        } catch (\Throwable $e) {
            $this->debug('[diagnostics] message could not be assembled: ' . $e::class . ': ' . $e->getMessage());

            return;
        }

        if ($message !== '') {
            $this->debug($message);
        }
    }

    /**
     * @param mixed $level A PSR-3 level string or a {@see Level}.
     * @param array<string,mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level instanceof Level) {
            $lvl = $level;
        } elseif (is_string($level) || $level instanceof \Stringable) {
            $lvl = Level::fromPsr((string) $level);
        } else {
            throw new \Psr\Log\InvalidArgumentException(sprintf(
                'CategoryLogger::log() expects $level to be a string, Stringable or %s instance, %s given.',
                Level::class,
                get_debug_type($level),
            ));
        }

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
