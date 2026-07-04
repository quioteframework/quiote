<?php

namespace Quiote\Telemetry;

use Quiote\Logging\Level;
use Quiote\Logging\Log;

/**
 * Static facade for the telemetry subsystem (mirrors {@see \Quiote\Logging\Log}).
 *
 * Configuration: {@see TelemetryBootstrap::configureFromConfig()} builds the
 * real provider from `telemetry.*` settings once per worker (called from
 * `Kernel::bootstrap()`). Until that has run — or if it declined because
 * telemetry is disabled, the SDK isn't installed, or construction failed —
 * every method below resolves to a shared no-op handle, so instrumenting a
 * call site is always safe regardless of configuration state:
 *   use Quiote\Telemetry\Trace;
 *   $span = Trace::span('Quiote.Routing', 'match');
 *   try { ... } finally { $span->end(); }
 */
final class Trace
{
    private function __construct() {}

    // --- configuration -----------------------------------------------------

    public static function setEnabled(bool $enabled): void
    {
        TraceRegistry::setEnabled($enabled);
    }

    public static function enabled(): bool
    {
        return TraceRegistry::isEnabled();
    }

    public static function reset(): void
    {
        TraceRegistry::reset();
    }

    /**
     * Enable/disable a dot-namespaced trace category prefix (e.g.
     * "Quiote.Routing"), mirroring {@see \Quiote\Logging\Log::setLevel()} —
     * except a disabled prefix cascades unconditionally to every descendant
     * category, it cannot be re-enabled by a more specific child entry.
     * Configured in index.php alongside `Log::setLevels(...)`, not via
     * `settings.xml`.
     */
    public static function setCategoryEnabled(string $categoryPrefix, bool $enabled): void
    {
        TraceRegistry::setCategoryEnabled($categoryPrefix, $enabled);
    }

    /** @param array<string,bool> $map category-prefix => enabled */
    public static function setCategories(array $map): void
    {
        TraceRegistry::setCategories($map);
    }

    /** Default for a category with no matching entry on its prefix chain. True unless set otherwise. */
    public static function setDefaultCategoryEnabled(bool $enabled): void
    {
        TraceRegistry::setDefaultCategoryEnabled($enabled);
    }

    // --- acquisition ---------------------------------------------------------

    /**
     * Open a span. $category is the dot-namespaced trace category (mirrors log
     * categories, e.g. "Quiote.Routing"; recorded as the `quiote.trace.category`
     * span attribute, and gated by {@see setCategoryEnabled()}/
     * {@see setCategories()}). $name is
     * the span's own name within that category (e.g. "match"). $kind defaults
     * to Internal; pass Server for a root request span, Client for outbound
     * calls, etc.
     *
     * A filtered-out category returns the same shared no-op handle as a
     * globally disabled/unwired telemetry state — no OTel context is touched,
     * so any span opened by code running "underneath" a filtered-out call
     * still correctly parents onto the nearest ancestor that WAS recorded,
     * without any extra propagation machinery.
     * @param array<string,mixed> $attributes
     */
    public static function span(string $category, string $name, array $attributes = [], SpanKind $kind = SpanKind::Internal): SpanHandle
    {
        if (!self::enabled()) {
            return NoopSpanHandle::instance();
        }
        if (!TraceRegistry::isCategoryEnabled($category)) {
            return NoopSpanHandle::instance();
        }
        $tracer = TraceRegistry::tracer();
        if ($tracer === null) {
            return NoopSpanHandle::instance();
        }
        try {
            $builder = $tracer->spanBuilder($name !== '' ? $name : '(unnamed)')
                ->setSpanKind($kind->value)
                ->setAttribute('quiote.trace.category', $category);
            if ($attributes !== []) {
                $builder->setAttributes($attributes);
            }
            $span = $builder->startSpan();
            $scope = $span->activate();
            return new OtelSpanHandle($span, $scope);
        } catch (\Throwable $e) {
            self::logFailure('span', $e);
            return NoopSpanHandle::instance();
        }
    }

    /**
     * The currently active span, or a no-op handle if none is open. This is
     * a *borrowed* reference: the returned handle does not own the span's
     * lifecycle (`ownsLifecycle: false`), so letting it go out of scope —
     * including as a bare expression like
     * `Trace::current()->recordException($e)->setStatusError(...);`, whose
     * temporary is destructed at the end of that statement — never ends the
     * real span. Only whoever actually created it via {@see span()} can end
     * it (explicitly, or via that handle's own destructor). Getting this
     * wrong previously caused a real, hard-to-spot bug: see the class
     * docblock on {@see OtelSpanHandle}.
     */
    public static function current(): SpanHandle
    {
        if (!self::enabled() || !TraceRegistry::hasRealProvider()) {
            return NoopSpanHandle::instance();
        }
        try {
            $span = \OpenTelemetry\API\Trace\Span::getCurrent();
            if (!$span->getContext()->isValid()) {
                return NoopSpanHandle::instance();
            }
            return new OtelSpanHandle($span, null, ownsLifecycle: false);
        } catch (\Throwable $e) {
            self::logFailure('current', $e);
            return NoopSpanHandle::instance();
        }
    }

    public static function metrics(): MeterHandle
    {
        if (!self::enabled()) {
            return NoopMeterHandle::instance();
        }
        return TraceRegistry::meterHandle() ?? NoopMeterHandle::instance();
    }

    private static function logFailure(string $operation, \Throwable $e): void
    {
        if (Log::for(self::class)->isEnabled(Level::Debug)) {
            Log::for(self::class)->debug('[Trace] ' . $operation . '() failed, falling back to no-op: ' . $e::class . ': ' . $e->getMessage());
        }
    }
}
