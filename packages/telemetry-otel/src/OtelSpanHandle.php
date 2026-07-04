<?php

namespace Quiote\Telemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Quiote\Logging\Level;
use Quiote\Logging\Log;

/**
 * Real {@see SpanHandle}, wrapping an active OpenTelemetry {@see SpanInterface}.
 * If the span was activated (pushed onto the current context via
 * {@see SpanInterface::activate()} when {@see Trace::span()} created it), the
 * owning {@see ScopeInterface} is detached exactly once, when {@see end()}
 * runs.
 *
 * A handle obtained from {@see Trace::current()} is a *borrowed* reference —
 * it didn't create the span and doesn't own its lifecycle, so
 * `$ownsLifecycle = false` there. This matters: `Trace::current()` is often
 * used inline as a bare expression, e.g.
 * `Trace::current()->recordException($e)->setStatusError(...);` — the
 * temporary `OtelSpanHandle` this creates has no other reference and is
 * destructed at the end of that statement. If `__destruct()` unconditionally
 * called `end()`, that would end the REAL underlying span the caller merely
 * borrowed a reference to, before whoever actually owns it (e.g.
 * `TelemetryMiddleware`, still further up the call stack) gets a chance to —
 * silently discarding every mutation made after that point, since a real
 * OTel span ignores `setStatus()`/`recordException()`/etc. once ended. This
 * is exactly the bug an earlier version of this file had, caught during the
 * OTel Collector end-to-end verification (docs/OPENTELEMETRY_E2E_VERIFICATION.md):
 * `RoutingMiddleware` captures `Trace::current()` into a local `$root`
 * variable to rename it on a successful match; that local going out of scope
 * (including mid-exception-unwind) was silently ending the root span long
 * before `TelemetryMiddleware`'s own `finally` block ran, so an action
 * exception's Error status never made it onto the exported root span. An
 * explicit `->end()` call is still always honored, on any handle — this only
 * changes whether *destruction* implies ending.
 *
 * Every mutator is wrapped so a call site can never crash the request: the
 * API's own type hints (e.g. `bool|int|float|string|array|null` for
 * attribute values) are enforced by the real SDK at the call boundary, so
 * passing an object/resource/etc — a caller bug, or hostile/unexpected
 * instrumentation input — throws a TypeError there. That is swallowed and
 * logged at debug level rather than propagating, matching the no-op layer's
 * "instrumenting a call site is always safe" guarantee.
 */
final class OtelSpanHandle implements SpanHandle
{
    private bool $ended = false;

    public function __construct(
        private readonly SpanInterface $span,
        private readonly ?ScopeInterface $scope = null,
        private readonly bool $ownsLifecycle = true,
    ) {
    }

    public function updateName(string $name): static
    {
        $this->safely(fn() => $this->span->updateName($name !== '' ? $name : '(unnamed)'));
        return $this;
    }

    public function setAttribute(string $key, mixed $value): static
    {
        $this->safely(fn() => $this->span->setAttribute($key, $value));
        return $this;
    }

    public function setAttributes(array $attributes): static
    {
        $this->safely(fn() => $this->span->setAttributes($attributes));
        return $this;
    }

    public function addEvent(string $name, array $attributes = []): static
    {
        $this->safely(fn() => $this->span->addEvent($name, $attributes));
        return $this;
    }

    public function recordException(\Throwable $e): static
    {
        $this->safely(fn() => $this->span->recordException($e));
        return $this;
    }

    public function setStatusError(?string $description = null): static
    {
        $this->safely(fn() => $this->span->setStatus(StatusCode::STATUS_ERROR, $description));
        return $this;
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->safely(fn() => $this->span->end());
        $this->safely(fn() => $this->scope?->detach());
    }

    public function __destruct()
    {
        if ($this->ownsLifecycle) {
            $this->end();
        }
    }

    public function traceId(): ?string
    {
        try {
            $context = $this->span->getContext();
            return $context->isValid() ? $context->getTraceId() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function spanId(): ?string
    {
        try {
            $context = $this->span->getContext();
            return $context->isValid() ? $context->getSpanId() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (Log::for(self::class)->isEnabled(Level::Debug)) {
                Log::for(self::class)->debug('[OtelSpanHandle] operation failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }
}
