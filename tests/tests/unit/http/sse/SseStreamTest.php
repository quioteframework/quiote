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

    public function testReadPullsEventsIncrementallyAndRespectsTheRequestedLength(): void
    {
        $stream = new SseStream(['ab', 'cd']);

        // "data: ab\n\n" is 10 bytes; a 4-byte read must not drain the second event.
        $this->assertSame('data', $stream->read(4));
        $this->assertFalse($stream->eof());
        $this->assertSame(": ab\n\n", $stream->read(6));
        $this->assertSame("data: cd\n\n", $stream->read(64));
        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(64));
    }

    public function testReadOnlyProducesEventsAsTheyAreAskedFor(): void
    {
        $produced = 0;
        $generator = (static function () use (&$produced): iterable {
            foreach (['one', 'two', 'three'] as $value) {
                $produced++;
                yield $value;
            }
        })();

        $stream = new SseStream($generator);
        $stream->read(1);

        // Streaming, not buffering: asking for one byte must not drain the generator.
        // The cursor sits one event ahead because it has to test validity to know eof().
        $this->assertLessThan(3, $produced);
    }

    public function testReadRejectsANegativeLength(): void
    {
        $stream = new SseStream(['x']);
        $this->expectException(RuntimeException::class);
        $stream->read(-1);
    }

    public function testReadAfterDrainingViaGetContentsThrows(): void
    {
        $stream = new SseStream(['x']);
        $stream->getContents();

        $this->expectException(RuntimeException::class);
        $stream->read(10);
    }

    public function testWriteToAfterReadThrowsRatherThanLosingEvents(): void
    {
        $stream = new SseStream(['x', 'y']);
        $stream->read(1);

        $this->expectException(RuntimeException::class);
        $stream->writeTo(static fn(): bool => true);
    }

    public function testRewindIsAllowedBeforeAnythingHasBeenProducedAndRefusedAfter(): void
    {
        $stream = new SseStream(['x']);
        $stream->rewind();
        $this->assertSame("data: x\n\n", $stream->read(64));

        $this->expectException(RuntimeException::class);
        $stream->rewind();
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
