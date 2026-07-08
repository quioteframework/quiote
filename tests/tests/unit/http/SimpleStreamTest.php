<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\SimpleStream;

class SimpleStreamTest extends TestCase
{
    public function testFromStringRoundTrip(): void
    {
        $stream = SimpleStream::fromString('hello world');
        $this->assertSame('hello world', (string) $stream);
        $stream->rewind();
        $this->assertSame('hello world', $stream->getContents());
    }

    public function testReadReturnsRequestedLength(): void
    {
        $stream = SimpleStream::fromString('abcdef');
        $stream->rewind();
        $this->assertSame('abc', $stream->read(3));
        $this->assertSame('def', $stream->read(3));
    }

    public function testReadWithNonPositiveLengthReturnsEmptyStringWithoutError(): void
    {
        // fread() rejects lengths <= 0 (an int<1,max> is required); read() must
        // guard against that instead of forwarding an invalid length.
        $stream = SimpleStream::fromString('abcdef');
        $stream->rewind();
        $this->assertSame('', $stream->read(0));
        $this->assertSame('', $stream->read(-5));
        // Stream position must be untouched by the rejected reads.
        $this->assertSame('abc', $stream->read(3));
    }

    public function testWriteToNonWritableStreamThrows(): void
    {
        $resource = fopen('php://temp', 'r');
        $this->assertNotFalse($resource);
        $stream = new SimpleStream($resource);
        $this->expectException(RuntimeException::class);
        $stream->write('x');
    }

    public function testConstructorFallsBackToTempStreamForClosedResource(): void
    {
        // A closed handle is no longer is_resource() at the point the
        // constructor checks it, exercising the same fallback branch as
        // being handed a genuinely invalid resource.
        $closed = fopen('php://temp', 'r');
        $this->assertNotFalse($closed);
        fclose($closed);

        $stream = new SimpleStream($closed);
        $stream->write('data');
        $stream->rewind();
        $this->assertSame('data', $stream->getContents());
    }

    public function testCloseDetachAndMetadata(): void
    {
        $stream = SimpleStream::fromString('x');
        $meta = $stream->getMetadata();
        $this->assertIsArray($meta);
        $resource = $stream->detach();
        $this->assertIsResource($resource);
        $stream->close();
    }

    public function testOperationsOnDetachedStreamThrow(): void
    {
        // Once detach() hands off the underlying resource, every other
        // stream operation must fail loudly rather than passing null into
        // a native function expecting a resource.
        $stream = SimpleStream::fromString('data');
        $stream->detach();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is detached');
        $stream->getContents();
    }

    public function testToStringOnDetachedStreamReturnsEmptyString(): void
    {
        // __toString() cannot throw, so it must swallow the failure and
        // fall back to an empty string instead of erroring.
        $stream = SimpleStream::fromString('data');
        $stream->detach();

        $this->assertSame('', (string) $stream);
    }
}
