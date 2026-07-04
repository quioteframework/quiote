<?php

use PHPUnit\Framework\TestCase;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\ArrayValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Metrics\V1\Gauge;
use Opentelemetry\Proto\Metrics\V1\Histogram;
use Opentelemetry\Proto\Metrics\V1\HistogramDataPoint;
use Opentelemetry\Proto\Metrics\V1\Metric;
use Opentelemetry\Proto\Metrics\V1\NumberDataPoint;
use Opentelemetry\Proto\Metrics\V1\ResourceMetrics;
use Opentelemetry\Proto\Metrics\V1\ScopeMetrics;
use Opentelemetry\Proto\Metrics\V1\Sum;
use Opentelemetry\Proto\Resource\V1\Resource;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use Opentelemetry\Proto\Trace\V1\Status;
use Quiote\Telemetry\Dashboard\MalformedRequestException;
use Quiote\Telemetry\Dashboard\OtlpDecoder;

/**
 * Feeds the decoder real, protobuf-serialized OTLP export requests (built
 * in-test via the actual generated message classes, the same ones the OTel
 * PHP OTLP exporter uses) and asserts on the flattened ReceivedSpan/
 * ReceivedMetric values -- plus adversarial/malformed input, since a
 * hostile or buggy exporter must never crash the dashboard.
 */
class OtlpDecoderTest extends TestCase
{
    private OtlpDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new OtlpDecoder();
    }

    public function testDecodesASpanWithAttributesAndResourceAttributes(): void
    {
        $span = new Span();
        $span->setTraceId(str_repeat("\x11", 16));
        $span->setSpanId(str_repeat("\x22", 8));
        $span->setParentSpanId(str_repeat("\x33", 8));
        $span->setName('GET /about');
        $span->setKind(2);
        $span->setStartTimeUnixNano(1_000_000_000);
        $span->setEndTimeUnixNano(1_012_500_000);
        $status = new Status();
        $status->setCode(2);
        $status->setMessage('boom');
        $span->setStatus($status);
        $span->setAttributes([$this->stringAttr('http.route', '/about'), $this->boolAttr('cache.hit', false)]);

        $request = $this->traceRequest([$span], ['service.name' => 'quiote-sample-app']);
        $decoded = $this->decoder->decodeTraces($request->serializeToString(), 'application/x-protobuf');

        $this->assertCount(1, $decoded);
        $received = $decoded[0];
        $this->assertSame('GET /about', $received->name);
        $this->assertSame(str_repeat('11', 16), $received->traceId);
        $this->assertSame(str_repeat('22', 8), $received->spanId);
        $this->assertSame(str_repeat('33', 8), $received->parentSpanId);
        $this->assertFalse($received->isRoot());
        $this->assertTrue($received->isError());
        $this->assertSame('boom', $received->statusMessage);
        $this->assertEqualsWithDelta(12.5, $received->durationMillis(), 0.001);
        $this->assertSame(['http.route' => '/about', 'cache.hit' => false], $received->attributes);
        $this->assertSame('quiote-sample-app', $received->serviceName());
    }

    public function testRootSpanHasNoParentSpanId(): void
    {
        $span = new Span();
        $span->setTraceId(str_repeat("\x01", 16));
        $span->setSpanId(str_repeat("\x02", 8));
        $span->setParentSpanId('');
        $span->setName('GET /');
        $status = new Status();
        $status->setCode(0);
        $span->setStatus($status);

        $request = $this->traceRequest([$span]);
        $decoded = $this->decoder->decodeTraces($request->serializeToString(), 'application/x-protobuf');

        $this->assertNull($decoded[0]->parentSpanId);
        $this->assertTrue($decoded[0]->isRoot());
        $this->assertFalse($decoded[0]->isError());
    }

    public function testNestedArrayAndKvlistAttributeValuesAreFlattened(): void
    {
        $span = new Span();
        $span->setTraceId(str_repeat("\x01", 16));
        $span->setSpanId(str_repeat("\x02", 8));
        $span->setName('nested');

        $arrayValue = new ArrayValue();
        $arrayValue->setValues([$this->scalarAny('a'), $this->scalarAny('b')]);
        $arrayAny = new AnyValue();
        $arrayAny->setArrayValue($arrayValue);
        $arrayAttr = new KeyValue();
        $arrayAttr->setKey('list');
        $arrayAttr->setValue($arrayAny);

        $span->setAttributes([$arrayAttr]);

        $request = $this->traceRequest([$span]);
        $decoded = $this->decoder->decodeTraces($request->serializeToString(), 'application/x-protobuf');

        $this->assertSame(['list' => ['a', 'b']], $decoded[0]->attributes);
    }

    public function testMultipleResourceSpansAndScopeSpansAreAllDecoded(): void
    {
        $spanA = new Span();
        $spanA->setTraceId(str_repeat("\x01", 16));
        $spanA->setSpanId(str_repeat("\x01", 8));
        $spanA->setName('a');

        $spanB = new Span();
        $spanB->setTraceId(str_repeat("\x02", 16));
        $spanB->setSpanId(str_repeat("\x02", 8));
        $spanB->setName('b');

        $request = new ExportTraceServiceRequest();
        $request->setResourceSpans([
            $this->resourceSpans([$spanA], ['service.name' => 'svc-a']),
            $this->resourceSpans([$spanB], ['service.name' => 'svc-b']),
        ]);

        $decoded = $this->decoder->decodeTraces($request->serializeToString(), 'application/x-protobuf');

        $this->assertCount(2, $decoded);
        $names = array_map(static fn($s) => $s->name, $decoded);
        $this->assertSame(['a', 'b'], $names);
        $this->assertSame('svc-a', $decoded[0]->serviceName());
        $this->assertSame('svc-b', $decoded[1]->serviceName());
    }

    public function testDecodesJsonEncodedTraceRequest(): void
    {
        $span = new Span();
        $span->setTraceId(str_repeat("\x09", 16));
        $span->setSpanId(str_repeat("\x0a", 8));
        $span->setName('json-span');

        $request = $this->traceRequest([$span]);
        $json = $request->serializeToJsonString();

        $decoded = $this->decoder->decodeTraces($json, 'application/json');

        $this->assertCount(1, $decoded);
        $this->assertSame('json-span', $decoded[0]->name);
    }

    public function testGarbageBytesThrowMalformedRequestExceptionRatherThanCrashing(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->decoder->decodeTraces("\xFF\xFF\xFF not protobuf at all", 'application/x-protobuf');
    }

    public function testEmptyBodyDecodesToNoSpans(): void
    {
        $decoded = $this->decoder->decodeTraces('', 'application/x-protobuf');
        $this->assertSame([], $decoded);
    }

    public function testDecodesGaugeMetric(): void
    {
        $point = new NumberDataPoint();
        $point->setAsDouble(1024.0);
        $point->setTimeUnixNano(42);

        $gauge = new Gauge();
        $gauge->setDataPoints([$point]);

        $metric = new Metric();
        $metric->setName('quiote.worker.memory.rss');
        $metric->setGauge($gauge);

        $decoded = $this->decoder->decodeMetrics($this->metricsRequest([$metric])->serializeToString(), 'application/x-protobuf');

        $this->assertCount(1, $decoded);
        $this->assertSame('gauge', $decoded[0]->type);
        $this->assertSame(1024.0, $decoded[0]->dataPoints[0]->value);
        $this->assertNull($decoded[0]->dataPoints[0]->count);
    }

    public function testDecodesSumMetricWithIntegerValue(): void
    {
        $point = new NumberDataPoint();
        $point->setAsInt(7);

        $sum = new Sum();
        $sum->setDataPoints([$point]);
        $sum->setIsMonotonic(true);

        $metric = new Metric();
        $metric->setName('http.server.request.count');
        $metric->setSum($sum);

        $decoded = $this->decoder->decodeMetrics($this->metricsRequest([$metric])->serializeToString(), 'application/x-protobuf');

        $this->assertSame('sum', $decoded[0]->type);
        $this->assertSame(7.0, $decoded[0]->dataPoints[0]->value);
    }

    public function testDecodesHistogramMetricAndComputesMean(): void
    {
        $point = new HistogramDataPoint();
        $point->setCount(4);
        $point->setSum(40.0);

        $histogram = new Histogram();
        $histogram->setDataPoints([$point]);

        $metric = new Metric();
        $metric->setName('http.server.request.duration');
        $metric->setHistogram($histogram);

        $decoded = $this->decoder->decodeMetrics($this->metricsRequest([$metric])->serializeToString(), 'application/x-protobuf');

        $this->assertSame('histogram', $decoded[0]->type);
        $this->assertSame(4, $decoded[0]->dataPoints[0]->count);
        $this->assertSame(10.0, $decoded[0]->dataPoints[0]->mean());
    }

    public function testUnrecognizedMetricTypeIsSkippedNotCrashed(): void
    {
        // A Metric with no oneof "data" set at all (unknown/future type).
        $metric = new Metric();
        $metric->setName('mystery');

        $decoded = $this->decoder->decodeMetrics($this->metricsRequest([$metric])->serializeToString(), 'application/x-protobuf');

        $this->assertSame([], $decoded);
    }

    public function testGarbageBytesForMetricsAlsoThrowMalformedRequestException(): void
    {
        $this->expectException(MalformedRequestException::class);
        $this->decoder->decodeMetrics('not protobuf', 'application/x-protobuf');
    }

    // --- helpers -------------------------------------------------------

    private function stringAttr(string $key, string $value): KeyValue
    {
        $any = new AnyValue();
        $any->setStringValue($value);
        $kv = new KeyValue();
        $kv->setKey($key);
        $kv->setValue($any);

        return $kv;
    }

    private function boolAttr(string $key, bool $value): KeyValue
    {
        $any = new AnyValue();
        $any->setBoolValue($value);
        $kv = new KeyValue();
        $kv->setKey($key);
        $kv->setValue($any);

        return $kv;
    }

    private function scalarAny(string $value): AnyValue
    {
        $any = new AnyValue();
        $any->setStringValue($value);

        return $any;
    }

    /** @param Span[] $spans @param array<string,string> $resourceAttributes */
    private function resourceSpans(array $spans, array $resourceAttributes = []): ResourceSpans
    {
        $scopeSpans = new ScopeSpans();
        $scopeSpans->setSpans($spans);

        $resource = new Resource();
        $resource->setAttributes(array_map(
            fn(string $k, string $v) => $this->stringAttr($k, $v),
            array_keys($resourceAttributes),
            array_values($resourceAttributes),
        ));

        $resourceSpans = new ResourceSpans();
        $resourceSpans->setResource($resource);
        $resourceSpans->setScopeSpans([$scopeSpans]);

        return $resourceSpans;
    }

    /** @param Span[] $spans @param array<string,string> $resourceAttributes */
    private function traceRequest(array $spans, array $resourceAttributes = []): ExportTraceServiceRequest
    {
        $request = new ExportTraceServiceRequest();
        $request->setResourceSpans([$this->resourceSpans($spans, $resourceAttributes)]);

        return $request;
    }

    /** @param Metric[] $metrics */
    private function metricsRequest(array $metrics): ExportMetricsServiceRequest
    {
        $scopeMetrics = new ScopeMetrics();
        $scopeMetrics->setMetrics($metrics);

        $resourceMetrics = new ResourceMetrics();
        $resourceMetrics->setScopeMetrics([$scopeMetrics]);

        $request = new ExportMetricsServiceRequest();
        $request->setResourceMetrics([$resourceMetrics]);

        return $request;
    }
}
