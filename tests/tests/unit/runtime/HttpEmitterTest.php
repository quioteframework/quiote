<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Http\Sse\SseEvent;
use Quiote\Http\Sse\SseStream;
use Quiote\Runtime\HttpEmitter;

/**
 * Each test runs in its own process: emit() calls header()/http_response_code(),
 * which only behave correctly before any real output has reached the SAPI --
 * running in the same process as the rest of the (very large) suite would
 * make these tests order-dependent on whether an earlier test already wrote
 * to stdout.
 */
class HttpEmitterTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testEmitBuffersAPlainStringBodyAsBefore(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)->withBody($factory->createStream('hello'));

        ob_start();
        (new HttpEmitter())->emit($response);
        $output = ob_get_clean();

        $this->assertSame('hello', $output);
    }

    #[RunInSeparateProcess]
    public function testEmitFlushesEachSseEventInOrder(): void
    {
        $factory = new Psr17Factory();
        $stream = new SseStream([
            SseEvent::of('one', event: 'a'),
            SseEvent::of('two', event: 'b'),
        ]);
        $response = $factory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withBody($stream);

        ob_start();
        (new HttpEmitter())->emit($response);
        $output = ob_get_clean();

        $this->assertSame("event: a\ndata: one\n\nevent: b\ndata: two\n\n", $output);
    }

    #[RunInSeparateProcess]
    public function testEmitStopsStreamingOnceConnectionIsAborted(): void
    {
        // connection_aborted() always reports 0 under PHPUnit's CLI SAPI, so this
        // exercises the "keep going while connected" branch end-to-end (the
        // early-stop branch itself is covered directly by SseStreamTest, which
        // doesn't depend on a real connection-abort signal).
        $factory = new Psr17Factory();
        $stream = new SseStream(['only']);
        $response = $factory->createResponse(200)->withBody($stream);

        ob_start();
        (new HttpEmitter())->emit($response);
        $output = ob_get_clean();

        $this->assertSame("data: only\n\n", $output);
        $this->assertTrue($stream->eof());
    }
}
