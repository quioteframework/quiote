<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\Rector\ContextResidueReporter\Source;

/**
 * Something with a getContext() that answers anything but a Quiote Context -- an OpenTelemetry span
 * is the real case, and the framework's own Quiote\Telemetry\Trace contains one.
 */
final class SpanLike
{
    public function getContext(): ForeignContextValue
    {
        return new ForeignContextValue();
    }
}
