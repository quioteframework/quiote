<?php

namespace Quiote\Telemetry\Dashboard;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Metrics\V1\HistogramDataPoint;
use Opentelemetry\Proto\Metrics\V1\Metric;
use Opentelemetry\Proto\Metrics\V1\NumberDataPoint;
use Opentelemetry\Proto\Trace\V1\Span;

/**
 * Decodes OTLP `ExportTraceServiceRequest`/`ExportMetricsServiceRequest`
 * protobuf (or JSON) payloads -- exactly what the OTel PHP OTLP/HTTP exporter
 * sends, per `telemetry.otlp.protocol` -- into plain {@see ReceivedSpan}/
 * {@see ReceivedMetric} value objects. Only the metric shapes
 * {@see \Quiote\Middleware\TelemetryMiddleware} actually emits (gauge, sum,
 * histogram) are decoded; exponential histogram and summary metrics are
 * silently skipped rather than guessed at.
 *
 * A malformed/hostile payload (bad protobuf bytes, absurdly deep nested
 * attribute values) must never crash the dashboard: every public method here
 * wraps decode failures in {@see MalformedRequestException} for the receiver
 * to catch, and attribute flattening is depth-guarded.
 */
final class OtlpDecoder
{
    private const MAX_ATTRIBUTE_DEPTH = 5;

    /** @return ReceivedSpan[] */
    public function decodeTraces(string $body, string $contentType): array
    {
        $request = new ExportTraceServiceRequest();
        $this->merge($request, $body, $contentType, 'trace');

        $spans = [];
        foreach ($request->getResourceSpans() as $resourceSpans) {
            $resourceAttributes = $this->flattenAttributes($resourceSpans->getResource()?->getAttributes());
            foreach ($resourceSpans->getScopeSpans() as $scopeSpans) {
                foreach ($scopeSpans->getSpans() as $span) {
                    $spans[] = $this->decodeSpan($span, $resourceAttributes);
                }
            }
        }

        return $spans;
    }

    /** @return ReceivedMetric[] */
    public function decodeMetrics(string $body, string $contentType): array
    {
        $request = new ExportMetricsServiceRequest();
        $this->merge($request, $body, $contentType, 'metrics');

        $metrics = [];
        foreach ($request->getResourceMetrics() as $resourceMetrics) {
            $resourceAttributes = $this->flattenAttributes($resourceMetrics->getResource()?->getAttributes());
            foreach ($resourceMetrics->getScopeMetrics() as $scopeMetrics) {
                foreach ($scopeMetrics->getMetrics() as $metric) {
                    $decoded = $this->decodeMetric($metric, $resourceAttributes);
                    if ($decoded !== null) {
                        $metrics[] = $decoded;
                    }
                }
            }
        }

        return $metrics;
    }

    private function merge(Message $message, string $body, string $contentType, string $kind): void
    {
        try {
            if (str_contains($contentType, 'json')) {
                $message->mergeFromJsonString($body);
            } else {
                $message->mergeFromString($body);
            }
        } catch (\Throwable $e) {
            throw new MalformedRequestException(
                sprintf('Could not decode OTLP %s export request: %s', $kind, $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function decodeSpan(Span $span, array $resourceAttributes): ReceivedSpan
    {
        $parentSpanId = $span->getParentSpanId();
        $status = $span->getStatus();

        return new ReceivedSpan(
            traceId: bin2hex($span->getTraceId()),
            spanId: bin2hex($span->getSpanId()),
            parentSpanId: $parentSpanId !== '' ? bin2hex($parentSpanId) : null,
            name: $span->getName(),
            kind: $span->getKind(),
            startTimeUnixNano: $span->getStartTimeUnixNano(),
            endTimeUnixNano: $span->getEndTimeUnixNano(),
            statusCode: $status?->getCode() ?? 0,
            statusMessage: $status?->getMessage() ?? '',
            attributes: $this->flattenAttributes($span->getAttributes()),
            resourceAttributes: $resourceAttributes,
        );
    }

    /** @param array<string,mixed> $resourceAttributes */
    private function decodeMetric(Metric $metric, array $resourceAttributes): ?ReceivedMetric
    {
        [$type, $dataPoints] = match (true) {
            $metric->hasGauge() => ['gauge', $this->decodeNumberDataPoints($metric->getGauge()->getDataPoints())],
            $metric->hasSum() => ['sum', $this->decodeNumberDataPoints($metric->getSum()->getDataPoints())],
            $metric->hasHistogram() => ['histogram', $this->decodeHistogramDataPoints($metric->getHistogram()->getDataPoints())],
            default => [null, []],
        };

        if ($type === null) {
            return null;
        }

        return new ReceivedMetric($metric->getName(), $type, $dataPoints, $resourceAttributes);
    }

    /**
     * @param RepeatedField<NumberDataPoint> $points
     * @return ReceivedDataPoint[]
     */
    private function decodeNumberDataPoints(RepeatedField $points): array
    {
        $result = [];
        foreach ($points as $point) {
            $value = $point->hasAsInt() ? (float) $point->getAsInt() : $point->getAsDouble();
            $result[] = new ReceivedDataPoint(
                attributes: $this->flattenAttributes($point->getAttributes()),
                value: $value,
                count: null,
                timeUnixNano: $point->getTimeUnixNano(),
            );
        }

        return $result;
    }

    /**
     * @param RepeatedField<HistogramDataPoint> $points
     * @return ReceivedDataPoint[]
     */
    private function decodeHistogramDataPoints(RepeatedField $points): array
    {
        $result = [];
        foreach ($points as $point) {
            $result[] = new ReceivedDataPoint(
                attributes: $this->flattenAttributes($point->getAttributes()),
                value: $point->getSum(),
                count: (int) $point->getCount(),
                timeUnixNano: $point->getTimeUnixNano(),
            );
        }

        return $result;
    }

    /**
     * @param RepeatedField<KeyValue>|null $attributes
     * @return array<string,mixed>
     */
    private function flattenAttributes(?RepeatedField $attributes): array
    {
        if ($attributes === null) {
            return [];
        }

        $result = [];
        foreach ($attributes as $keyValue) {
            $result[$keyValue->getKey()] = $this->anyValueToScalar($keyValue->getValue(), 0);
        }

        return $result;
    }

    private function anyValueToScalar(?AnyValue $value, int $depth): mixed
    {
        if ($value === null || $depth > self::MAX_ATTRIBUTE_DEPTH) {
            return null;
        }

        return match (true) {
            $value->hasStringValue() => $value->getStringValue(),
            $value->hasBoolValue() => $value->getBoolValue(),
            $value->hasIntValue() => $value->getIntValue(),
            $value->hasDoubleValue() => $value->getDoubleValue(),
            $value->hasBytesValue() => bin2hex($value->getBytesValue()),
            $value->hasArrayValue() => array_map(
                fn(AnyValue $v) => $this->anyValueToScalar($v, $depth + 1),
                iterator_to_array($value->getArrayValue()->getValues()),
            ),
            $value->hasKvlistValue() => $this->flattenKvList($value->getKvlistValue()->getValues(), $depth + 1),
            default => null,
        };
    }

    /**
     * @param RepeatedField<KeyValue> $values
     * @return array<string,mixed>
     */
    private function flattenKvList(RepeatedField $values, int $depth): array
    {
        $result = [];
        foreach ($values as $keyValue) {
            $result[$keyValue->getKey()] = $this->anyValueToScalar($keyValue->getValue(), $depth);
        }

        return $result;
    }
}
