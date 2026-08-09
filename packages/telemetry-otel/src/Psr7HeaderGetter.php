<?php

namespace Quiote\Telemetry;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Psr\Http\Message\MessageInterface;

/**
 * Reads W3C `traceparent`/`tracestate` (or any other propagated header) off a
 * PSR-7 message for {@see \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::extract()}.
 * The SDK's default `ArrayAccessGetterSetter` expects array-like access,
 * which a PSR-7 message isn't — this bridges the two.
 */
final class Psr7HeaderGetter implements PropagationGetterInterface
{
    /**
     * The header names present on the carrier, in the message's own casing.
     *
     * An empty array when $carrier is not a PSR-7 message, since the
     * propagator hands over whatever it was given untyped.
     */
    public function keys(mixed $carrier): array
    {
        if (!$carrier instanceof MessageInterface) {
            return [];
        }
        return array_keys($carrier->getHeaders());
    }

    /**
     * The comma-joined value of header $key, matched case-insensitively.
     *
     * Null when $carrier is not a PSR-7 message or the header is absent or
     * empty, which the propagator treats as "nothing to extract".
     */
    public function get(mixed $carrier, string $key): ?string
    {
        if (!$carrier instanceof MessageInterface) {
            return null;
        }
        $value = $carrier->getHeaderLine($key);
        return $value === '' ? null : $value;
    }
}
