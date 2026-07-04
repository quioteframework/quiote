<?php

namespace Quiote\Telemetry;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

/**
 * The outbound counterpart to {@see Psr7HeaderGetter}: adapts a PSR-7 request
 * to OpenTelemetry's propagation *setter* so `TraceContextPropagator::inject()`
 * can write `traceparent`/`tracestate` onto an outgoing request. This is the
 * egress half of Phase 7 in docs/OPENTELEMETRY_PLAN.md, previously unbuilt for
 * want of an HTTP client to inject into.
 *
 * The carrier is passed and reassigned by reference because PSR-7 messages are
 * immutable — `withHeader()` returns a new instance — so `inject()`'s
 * `&$carrier` contract is exactly what lets the mutated request propagate back
 * to the caller.
 *
 * Like {@see Psr7HeaderGetter}, this implements an open-telemetry/context
 * interface directly, so it is only ever referenced behind a `Trace::enabled()`
 * gate (in {@see \Quiote\Http\Client\HttpClient}), at which point the SDK is
 * installed and the interface exists.
 */
final class Psr7HeaderSetter implements PropagationSetterInterface
{
    /**
     * @param \Psr\Http\Message\MessageInterface $carrier
     */
    public function set(&$carrier, string $key, string $value): void
    {
        $carrier = $carrier->withHeader($key, $value);
    }
}
