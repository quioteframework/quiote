<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quiote\Http\Sse\SseEvent;
use Quiote\Http\Sse\SseStream;
use Quiote\Runtime\Swoole\SwooleResponseEmitter;
use Quiote\Runtime\Swoole\SwooleResponseWriterInterface;

/** Records everything the emitter would have written to a Swoole response. */
final class RecordingSwooleWriter implements SwooleResponseWriterInterface
{
    public ?int $status = null;

    /** @var array<string, string|list<string>> */
    public array $headers = [];

    /** @var list<string> */
    public array $writes = [];

    public ?string $ended = null;

    /** Number of write() calls to accept before reporting the client is gone. */
    public int $acceptWrites = PHP_INT_MAX;

    public function status(int $code): void
    {
        $this->status = $code;
    }

    public function header(string $name, string|array $value): void
    {
        $this->headers[$name] = $value;
    }

    public function write(string $chunk): bool
    {
        if (count($this->writes) >= $this->acceptWrites) {
            return false;
        }
        $this->writes[] = $chunk;
        return true;
    }

    public function end(string $body = ''): void
    {
        $this->ended = $body;
    }
}

final class SwooleResponseEmitterTest extends TestCase
{
    public function testItReportsStreamingSupport(): void
    {
        $this->assertTrue((new SwooleResponseEmitter(new RecordingSwooleWriter()))->supportsStreaming());
    }

    public function testStatusHeadersAndBodyAreAllWritten(): void
    {
        $writer = new RecordingSwooleWriter();
        $psr17 = new Psr17Factory();
        $response = $psr17->createResponse(201)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream('{"ok":true}'));

        (new SwooleResponseEmitter($writer))->emit($response);

        $this->assertSame(201, $writer->status);
        $this->assertSame('application/json', $writer->headers['Content-Type']);
        $this->assertSame('{"ok":true}', $writer->ended);
        $this->assertSame([], $writer->writes);
    }

    public function testAnEmptyBodyStillEndsTheResponse(): void
    {
        $writer = new RecordingSwooleWriter();

        (new SwooleResponseEmitter($writer))->emit((new Psr17Factory())->createResponse(204));

        $this->assertSame(204, $writer->status);
        $this->assertSame('', $writer->ended);
    }

    public function testASingleValuedHeaderGoesOutAsAString(): void
    {
        $writer = new RecordingSwooleWriter();

        (new SwooleResponseEmitter($writer))->emit(
            (new Psr17Factory())->createResponse(200)->withHeader('X-One', 'only')
        );

        $this->assertSame('only', $writer->headers['X-One']);
    }

    public function testRepeatedHeadersGoOutAsAnArraySoNoneAreLost(): void
    {
        $writer = new RecordingSwooleWriter();
        $response = (new Psr17Factory())->createResponse(200)
            ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/');

        (new SwooleResponseEmitter($writer))->emit($response);

        // Repeated header() calls with a string overwrite in Swoole; the array
        // form is what keeps both cookies.
        $this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $writer->headers['Set-Cookie']);
    }

    public function testAnSseBodyIsWrittenEventByEventAndThenClosed(): void
    {
        $writer = new RecordingSwooleWriter();
        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withBody(new SseStream([
                SseEvent::of('one', event: 'a'),
                SseEvent::of('two', event: 'b'),
            ]));

        (new SwooleResponseEmitter($writer))->emit($response);

        $this->assertSame([
            "event: a\ndata: one\n\n",
            "event: b\ndata: two\n\n",
        ], $writer->writes);
        $this->assertSame('', $writer->ended);
    }

    public function testContentLengthIsSuppressedForAStreamedBody(): void
    {
        $writer = new RecordingSwooleWriter();
        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Length', '999')
            ->withHeader('Cache-Control', 'no-cache')
            ->withBody(new SseStream(['tick']));

        (new SwooleResponseEmitter($writer))->emit($response);

        // A streamed body has no length known up front, and announcing a wrong
        // one truncates the stream at the client.
        $this->assertArrayNotHasKey('Content-Length', $writer->headers);
        $this->assertSame('no-cache', $writer->headers['Cache-Control']);
    }

    public function testContentLengthIsKeptForAnOrdinaryBody(): void
    {
        $writer = new RecordingSwooleWriter();
        $psr17 = new Psr17Factory();
        $response = $psr17->createResponse(200)
            ->withHeader('Content-Length', '5')
            ->withBody($psr17->createStream('hello'));

        (new SwooleResponseEmitter($writer))->emit($response);

        $this->assertSame('5', $writer->headers['Content-Length']);
    }

    public function testAStreamStopsEarlyOnceTheClientHasGone(): void
    {
        $writer = new RecordingSwooleWriter();
        $writer->acceptWrites = 1;
        $produced = 0;
        $events = (static function () use (&$produced): iterable {
            while (true) {
                $produced++;
                yield 'tick';
            }
        })();

        (new SwooleResponseEmitter($writer))->emit(
            (new Psr17Factory())->createResponse(200)->withBody(new SseStream($events))
        );

        // write() returning false is this runtime's disconnect signal --
        // connection_aborted() always reports 0 under the CLI, so without it an
        // endless generator would spin forever for a client that is already gone.
        $this->assertCount(1, $writer->writes);
        $this->assertSame(2, $produced);
        $this->assertSame('', $writer->ended);
    }

    public function testAnEmptyEventStreamStillClosesTheResponse(): void
    {
        $writer = new RecordingSwooleWriter();

        (new SwooleResponseEmitter($writer))->emit(
            (new Psr17Factory())->createResponse(200)->withBody(new SseStream([]))
        );

        $this->assertSame([], $writer->writes);
        $this->assertSame('', $writer->ended);
    }
}
