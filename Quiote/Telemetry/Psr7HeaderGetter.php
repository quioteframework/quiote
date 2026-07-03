<?php

namespace Quiote\Telemetry;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Psr\Http\Message\MessageInterface;

/**
 * Reads W3C `traceparent`/`tracestate` (or any other propagated header) off a
 * PSR-7 message for {@see \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::extract()}
 * (docs/OPENTELEMETRY_PLAN.md, Phase 7). The SDK's default
 * `ArrayAccessGetterSetter` expects array-like access, which a PSR-7 message
 * isn't — this bridges the two.
 */
final class Psr7HeaderGetter implements PropagationGetterInterface
{
    public function keys($carrier): array
    {
        if (!$carrier instanceof MessageInterface) {
            return [];
        }
        return array_keys($carrier->getHeaders());
    }

    public function get($carrier, string $key): ?string
    {
        if (!$carrier instanceof MessageInterface) {
            return null;
        }
        $value = $carrier->getHeaderLine($key);
        return $value === '' ? null : $value;
    }
}
