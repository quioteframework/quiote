<?php

namespace Quiote\Telemetry;

/**
 * Mirrors OpenTelemetry's `SpanKind` constants
 * (`OpenTelemetry\API\Trace\SpanKind::KIND_*`) numerically 1:1, but as our own
 * framework-owned enum so {@see Trace::span()}'s signature never needs the
 * optional open-telemetry/api package to exist — PHP resolves a default
 * parameter value eagerly (unlike type hints, which resolve lazily at call
 * time), so a default referencing an optional class's constant would crash
 * every `Trace::span()` call with no explicit $kind when the SDK isn't
 * installed. An owned enum with matching int values sidesteps that entirely.
 */
enum SpanKind: int
{
    case Internal = 0;
    case Client = 1;
    case Server = 2;
    case Producer = 3;
    case Consumer = 4;
}
