<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\Sse\SseEvent;
use Quiote\Http\Sse\SseStream;

class SseStreamTest extends TestCase
{
    public function testToStringBuffersAllEventsFromAGenerator(): void
    {
        $generator = (static function (): iterable {
            yield SseEvent::of('one', event: 'a');
            yield SseEvent::of('two', event: 'b');
        })();

        $stream = new SseStream($generator);
        $this->assertSame("event: a\ndata: one\n\nevent: b\ndata: two\n\n", (string)$stream);
    }

    public function testPlainStringItemsAreWrappedAsDataOnlyEvents(): void
    {
        $stream = new SseStream(['first', 'second']);
        $this->assertSame("data: first\n\ndata: second\n\n", $stream->getContents());
    }

    public function testEmptyIterableProducesEmptyBody(): void
    {
        $stream = new SseStream([]);
        $this->assertSame('', $stream->getContents());
        $this->assertTrue($stream->eof());
    }

    public function testWriteToStopsEarlyWhenSinkReturnsFalse(): void
    {
        $events = ['first', 'second', 'third'];
        $seen = [];

        $stream = new SseStream($events);
        $stream->writeTo(function (string $chunk) use (&$seen): bool {
            $seen[] = $chunk;
            return count($seen) < 2;
        });

        $this->assertCount(2, $seen, 'writeTo() must stop as soon as the sink signals disconnect');
        $this->assertTrue($stream->eof());
    }

    public function testWriteIsNotSupported(): void
    {
        $stream = new SseStream([]);
        $this->expectException(RuntimeException::class);
        $stream->write('x');
    }

    public function testSeekIsNotSupported(): void
    {
        $stream = new SseStream([]);
        $this->assertFalse($stream->isSeekable());
        $this->expectException(RuntimeException::class);
        $stream->seek(0);
    }

    public function testReadIsNotSupported(): void
    {
        $stream = new SseStream([]);
        $this->expectException(RuntimeException::class);
        $stream->read(10);
    }

    public function testCloseAndDetachMarkStreamAsConsumed(): void
    {
        $stream = new SseStream(['x']);
        $this->assertFalse($stream->eof());
        $stream->close();
        $this->assertTrue($stream->eof());

        $stream2 = new SseStream(['x']);
        $this->assertNull($stream2->detach());
        $this->assertTrue($stream2->eof());
    }
}
