<?php

use PHPUnit\Framework\TestCase;
use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Trace\V1\ResourceSpans;
use Opentelemetry\Proto\Trace\V1\ScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span;
use Quiote\Telemetry\Dashboard\OtlpDecoder;
use Quiote\Telemetry\Dashboard\OtlpReceiver;
use Revolt\EventLoop;

/**
 * End-to-end against a real socket: binds a real OtlpReceiver on an
 * OS-assigned port, fires a real HTTP request at it from an independent PHP
 * subprocess (a genuinely separate OS process -- not a fork of the test
 * runner, to avoid corrupting PHPUnit's own process state), and asserts the
 * receiver decoded it and responded 200. This is the one thing
 * OtlpDecoderTest/HttpMessageParserTest can't prove on their own: that the
 * two compose correctly over a real Revolt-driven socket.
 */
class OtlpReceiverTest extends TestCase
{
    private const CLIENT_SCRIPT = <<<'PHP'
<?php
[, $port, $bodyFile] = $argv;
$body = file_get_contents($bodyFile);
usleep(100_000);
$client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2);
if ($client === false) {
    fwrite(STDERR, "connect failed: $errstr\n");
    exit(1);
}
$request = "POST /v1/traces HTTP/1.1\r\nContent-Type: application/x-protobuf\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
fwrite($client, $request);
echo stream_get_contents($client);
fclose($client);
PHP;

    public function testReceivesDecodesAndRespondsToARealTraceExport(): void
    {
        $span = new Span();
        $span->setTraceId(str_repeat("\x01", 16));
        $span->setSpanId(str_repeat("\x02", 8));
        $span->setName('GET /');

        $scopeSpans = new ScopeSpans();
        $scopeSpans->setSpans($this->repeatedField([$span], Span::class));
        $resourceSpans = new ResourceSpans();
        $resourceSpans->setScopeSpans($this->repeatedField([$scopeSpans], ScopeSpans::class));
        $request = new ExportTraceServiceRequest();
        $request->setResourceSpans($this->repeatedField([$resourceSpans], ResourceSpans::class));
        $body = $request->serializeToString();

        // A plain nullable local captured `use (&$x)` reports as permanently
        // null to PHPStan even after later reassignment, so shared mutable
        // state between the receiver callback and the timeout callback lives
        // on an object instead, whose property types PHPStan tracks properly.
        $state = new class {
            /** @var array<int, \Quiote\Telemetry\Dashboard\ReceivedSpan>|null */
            public ?array $receivedSpans = null;
            public ?OtlpReceiver $receiver = null;
            public ?string $timeoutId = null;
        };

        $state->receiver = new OtlpReceiver(
            '127.0.0.1',
            0,
            new OtlpDecoder(),
            function (array $spans) use ($state): void {
                $state->receivedSpans = $spans;
                if ($state->timeoutId !== null) {
                    EventLoop::cancel($state->timeoutId);
                }
                if ($state->receiver !== null) {
                    EventLoop::defer(fn() => $state->receiver->stop());
                }
            },
            function (array $metrics): void {
            },
        );
        $state->receiver->start();

        $bodyFile = tempnam(sys_get_temp_dir(), 'otlp-span-body');
        $scriptFile = tempnam(sys_get_temp_dir(), 'otlp-client') . '.php';
        file_put_contents($bodyFile, $body);
        file_put_contents($scriptFile, self::CLIENT_SCRIPT);

        $process = proc_open(
            [PHP_BINARY, $scriptFile, (string) $state->receiver->boundPort(), $bodyFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $state->timeoutId = EventLoop::delay(5.0, function () use ($state): void {
            $state->receiver->stop();
        });

        EventLoop::run();

        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);
        unlink($bodyFile);
        unlink($scriptFile);

        $this->assertNotNull($state->receivedSpans, 'Receiver never decoded a span within the timeout. stderr: ' . $stderr);
        $this->assertCount(1, $state->receivedSpans);
        $this->assertSame('GET /', $state->receivedSpans[0]->name);
    }

    public function testBoundPortThrowsBeforeStartHasBoundASocket(): void
    {
        $receiver = new OtlpReceiver(
            '127.0.0.1',
            0,
            new OtlpDecoder(),
            function (array $spans): void {
            },
            function (array $metrics): void {
            },
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot determine the bound port before start() has been called.');

        $receiver->boundPort();
    }

    public function testStopWithoutStartIsANoOp(): void
    {
        $receiver = new OtlpReceiver(
            '127.0.0.1',
            0,
            new OtlpDecoder(),
            function (array $spans): void {
            },
            function (array $metrics): void {
            },
        );

        $receiver->stop();

        $this->addToAssertionCount(1);
    }

    /**
     * @template T of Message
     * @param list<T> $items
     * @param class-string<T> $class
     * @return RepeatedField<T>
     */
    private function repeatedField(array $items, string $class): RepeatedField
    {
        $field = new RepeatedField(GPBType::MESSAGE, $class);
        foreach ($items as $item) {
            $field[] = $item;
        }

        return $field;
    }
}
