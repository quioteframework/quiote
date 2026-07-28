<?php

namespace Quiote\Http\Sse;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A write-once PSR-7 stream backed by an iterable of SseEvent (or plain
 * string) items, typically a generator produced by an
 * SseStreamingAction::streamEvents() implementation.
 *
 * There are three ways to drain it, and only one may be used per instance:
 *  - writeTo(), a push loop that hands each formatted event to a sink and stops
 *    early when the sink reports the client is gone. SapiEmitter uses this.
 *  - read()/eof(), an incremental pull for consumers whose only streaming API
 *    is chunk-at-a-time (the RoadRunner responder).
 *  - __toString()/getContents(), which buffers everything in one pass, for
 *    anything treating the body as an ordinary string (dev-exception
 *    rendering, HttpTestCase assertions).
 *
 * Mixing them throws rather than silently dropping events, since the backing
 * iterable can only be traversed once.
 */
final class SseStream implements StreamInterface
{
    private bool $consumed = false;

    /**
     * Formatted bytes produced by the iterable but not yet returned from
     * read(), for the incremental path only -- writeTo() never uses it.
     */
    private string $buffer = '';

    /**
     * Lazily created by read(); null until the incremental path is first used.
     * @var \Iterator<mixed, SseEvent|string>|null
     */
    private ?\Iterator $cursor = null;

    private bool $exhausted = false;

    /**
     * @param iterable<SseEvent|string> $events
     */
    public function __construct(private iterable $events)
    {
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function getContents(): string
    {
        $buffer = '';
        $this->writeTo(function (string $chunk) use (&$buffer): bool {
            $buffer .= $chunk;
            return true;
        });
        return $buffer;
    }

    /**
     * Drains the event iterable, formatting each item and passing it to
     * $sink. Stops early if $sink returns false (e.g. the client
     * disconnected mid-stream).
     */
    public function writeTo(callable $sink): void
    {
        if ($this->cursor !== null) {
            throw new RuntimeException('SseStream has already started producing events via read().');
        }
        foreach ($this->events as $item) {
            $event = $item instanceof SseEvent ? $item : SseEvent::of((string)$item);
            if ($sink($event->format()) === false) {
                $this->consumed = true;
                return;
            }
        }
        $this->consumed = true;
    }

    public function close(): void
    {
        $this->consumed = true;
    }

    public function detach()
    {
        $this->consumed = true;
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('SseStream has no byte position; it is not a seekable resource.');
    }

    public function eof(): bool
    {
        if ($this->cursor !== null) {
            return $this->exhausted && $this->buffer === '';
        }
        return $this->consumed;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('SseStream is not seekable.');
    }

    /**
     * Tolerated as a no-op while nothing has been consumed yet, because that is
     * how PSR-7 consumers conventionally open a body they are about to read
     * (RoadRunner's chunked responder does exactly this). Once events have
     * started flowing there is nothing to rewind to.
     */
    public function rewind(): void
    {
        if ($this->consumed || $this->cursor !== null) {
            throw new RuntimeException('SseStream is not seekable; it has already started producing events.');
        }
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('SseStream is not writable; produce events via the iterable passed to the constructor.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    /**
     * Pulls events on demand, returning at most $length bytes and keeping the
     * remainder for the next call. Blocks in the underlying generator exactly as
     * long as the next event takes to produce, so a consumer that reads in a
     * loop streams rather than buffers -- which is what makes SSE work on a
     * runtime whose only streaming API is "give me a chunk at a time"
     * (RoadRunner) rather than a write callback (the SAPI emitter, which uses
     * writeTo() instead).
     *
     * Mutually exclusive with writeTo()/getContents(): an iterable can only be
     * drained once, so mixing the two throws rather than silently losing events.
     */
    public function read($length): string
    {
        if ($length < 0) {
            throw new RuntimeException('SseStream::read() length must not be negative.');
        }
        if ($this->cursor === null && $this->consumed) {
            throw new RuntimeException('SseStream has already been drained via writeTo()/getContents().');
        }
        if ($length === 0) {
            return '';
        }

        $this->cursor ??= $this->openCursor();

        // At most one event per call, even when $length would hold several.
        // PSR-7 allows a short read, and stopping at the event boundary is what
        // lets a chunk-oriented consumer forward each event the moment it is
        // produced instead of batching until $length is full -- which for an SSE
        // stream would defeat the point.
        if ($this->buffer === '' && !$this->exhausted) {
            $this->pullNext();
        }

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($chunk));
        if ($this->exhausted && $this->buffer === '') {
            $this->consumed = true;
        }
        return $chunk;
    }

    /** @return \Iterator<mixed, SseEvent|string> */
    private function openCursor(): \Iterator
    {
        $events = $this->events;
        if ($events instanceof \Iterator) {
            return $events;
        }
        if ($events instanceof \IteratorAggregate) {
            /** @var \Iterator<mixed, SseEvent|string> $inner */
            $inner = $events->getIterator();
            return $inner;
        }
        return new \ArrayIterator(is_array($events) ? $events : iterator_to_array($events, false));
    }

    private function pullNext(): void
    {
        $cursor = $this->cursor;
        if ($cursor === null) {
            $this->exhausted = true;
            return;
        }
        if (!$cursor->valid()) {
            $this->exhausted = true;
            return;
        }
        $item = $cursor->current();
        $event = $item instanceof SseEvent ? $item : SseEvent::of((string)$item);
        $this->buffer .= $event->format();
        $cursor->next();
        if (!$cursor->valid()) {
            $this->exhausted = true;
        }
    }

    public function getMetadata($key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
