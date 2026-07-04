<?php

namespace Quiote\Telemetry;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SDK\Trace\Span;

/**
 * Head-based force-sample escape hatch: "trace this one request" without
 * touching the global sampling ratio.
 *
 * Wraps a delegate sampler. If the span-creation attributes carry
 * `quiote.force_sample = true` (set by {@see \Quiote\Middleware\
 * TelemetryMiddleware} when the configured force-sample header or PSR-7
 * request attribute is present), the decision is RECORD_AND_SAMPLE
 * unconditionally — bypassing the delegate (ratio, parent, everything) for
 * this span. Every other span defers entirely to the delegate.
 *
 * This is a *head* decision made at span-creation time, matching the plan's
 * explicit stance that outcome-based ("keep failed/slow requests") tail
 * sampling belongs in an OTel Collector downstream, not here.
 */
final class ForceSampleSampler implements SamplerInterface
{
    public function __construct(
        private readonly SamplerInterface $delegate,
        private readonly string $attributeKey = 'quiote.force_sample',
    ) {
    }

    public function shouldSample(
        ContextInterface $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        AttributesInterface $attributes,
        array $links,
    ): SamplingResult {
        if ($attributes->get($this->attributeKey) === true) {
            $traceState = Span::fromContext($parentContext)->getContext()->getTraceState();
            return new SamplingResult(SamplingResult::RECORD_AND_SAMPLE, [], $traceState);
        }
        return $this->delegate->shouldSample($parentContext, $traceId, $spanName, $spanKind, $attributes, $links);
    }

    public function getDescription(): string
    {
        return 'ForceSampleSampler{' . $this->delegate->getDescription() . '}';
    }
}
