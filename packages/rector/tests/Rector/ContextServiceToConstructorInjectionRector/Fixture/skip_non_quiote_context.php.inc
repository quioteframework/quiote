<?php

namespace Quiote\Rector\Tests\Fixture;

use OpenTelemetry\SDK\Trace\Span;

final class SpanContextIsNotAQuioteContext
{
    public function run(Span $span): bool
    {
        // The collision that exists in first-party framework source today
        // (Quiote\Telemetry\Trace). Not a Quiote Context; must not be touched.
        return $span->getContext()->isValid();
    }
}
