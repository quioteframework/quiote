<?php

use PHPUnit\Framework\TestCase;
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
        $scopeSpans->setSpans([$span]);
        $resourceSpans = new ResourceSpans();
        $resourceSpans->setScopeSpans([$scopeSpans]);
        $request = new ExportTraceServiceRequest();
        $request->setResourceSpans([$resourceSpans]);
        $body = $request->serializeToString();

        $receivedSpans = null;
        $receiver = null;
        $timeoutId = null;

        $receiver = new OtlpReceiver(
            '127.0.0.1',
            0,
            new OtlpDecoder(),
            function (array $spans) use (&$receivedSpans, &$receiver, &$timeoutId): void {
                $receivedSpans = $spans;
                EventLoop::cancel($timeoutId);
                EventLoop::defer(fn() => $receiver->stop());
            },
            function (array $metrics): void {
            },
        );
        $receiver->start();

        $bodyFile = tempnam(sys_get_temp_dir(), 'otlp-span-body');
        $scriptFile = tempnam(sys_get_temp_dir(), 'otlp-client') . '.php';
        file_put_contents($bodyFile, $body);
        file_put_contents($scriptFile, self::CLIENT_SCRIPT);

        $process = proc_open(
            [PHP_BINARY, $scriptFile, (string) $receiver->boundPort(), $bodyFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $timeoutId = EventLoop::delay(5.0, function () use ($receiver): void {
            $receiver->stop();
        });

        EventLoop::run();

        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);
        unlink($bodyFile);
        unlink($scriptFile);

        $this->assertNotNull($receivedSpans, 'Receiver never decoded a span within the timeout. stderr: ' . $stderr);
        $this->assertCount(1, $receivedSpans);
        $this->assertSame('GET /', $receivedSpans[0]->name);
    }
}
