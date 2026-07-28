<?php

namespace Quiote\Http\Sse;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A write-once PSR-7 stream backed by an iterable of SseEvent (or plain
 * string) items, typically a generator produced by an
 * SseStreamingAction::streamEvents() implementation.
 *
 * HttpEmitter recognises this type and flushes each formatted event to the
 * client as it's produced via writeTo(); everything else that merely reads
 * the body as a PSR-7 StreamInterface (dev-exception rendering, HttpTestCase
 * assertions, etc.) gets the fully-buffered content via __toString()/
 * getContents(), which drains the iterable in one pass.
 */
final class SseStream implements StreamInterface
{
    private bool $consumed = false;

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

    public function rewind(): void
    {
        throw new RuntimeException('SseStream is not seekable.');
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

    public function read($length): string
    {
        throw new RuntimeException('SseStream does not support byte-oriented reads; use getContents() or writeTo().');
    }

    public function getMetadata($key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
