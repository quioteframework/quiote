<?php
namespace Quiote\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A minimal PSR-7 `StreamInterface` over a plain PHP stream resource, so the framework can
 * produce response bodies without depending on a third-party PSR-7 implementation.
 *
 * Used for the bodies built by {@see \Quiote\Response\PsrResponseBuilder} and
 * {@see PsrResponseAdapter}; {@see fromString()} is the common entry point and wraps content
 * in a rewound `php://temp` handle.
 *
 * Deliberately thin: {@see getSize()} always answers null rather than stat'ing the handle,
 * and {@see __toString()} rewinds first and returns an empty string on any failure, since
 * PHP forbids throwing from string conversion. Every other operation on a detached stream
 * throws `RuntimeException`. Constructing with a non-resource does not fail — a fresh
 * `php://temp` handle is substituted.
 */
class SimpleStream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    /**
     * @param resource $resource
     */
    public function __construct($resource)
    {
        if(!is_resource($resource)) {
            $resource = fopen('php://temp','r+');
            if($resource === false) throw new RuntimeException('Cannot create temp stream');
        }
        $this->resource = $resource;
    }

    /**
     * Wraps $content in a new read/write `php://temp` handle, rewound to the start so the
     * content can be read back immediately.
     *
     * @throws RuntimeException when the temporary handle cannot be opened
     */
    public static function fromString(string $content): self
    {
        $h = fopen('php://temp','r+');
        if ($h === false) {
            throw new RuntimeException('Cannot create temp stream');
        }
        fwrite($h,$content);
        rewind($h);
        return new self($h);
    }

    /**
     * @return resource
     */
    private function getResource()
    {
        if ($this->resource === null) {
            throw new RuntimeException('Stream is detached');
        }
        return $this->resource;
    }

    public function __toString(): string
    { try { $this->seek(0); return stream_get_contents($this->getResource()) ?: ''; } catch(\Throwable) { return ''; } }
    /** Closes the underlying handle when one is still open; a detached or already closed stream is a no-op. */
    public function close(): void { if(is_resource($this->resource)) fclose($this->resource); }
    /**
     * Releases the underlying handle to the caller and leaves this stream detached.
     *
     * Every subsequent operation other than `close()` and `__toString()` throws
     * `RuntimeException`, since there is no resource left to act on.
     *
     * @return resource|null the handle, or null when the stream was already detached
     */
    public function detach() { $r=$this->resource; $this->resource=null; return $r; }
    /** Always returns null: the size of the wrapped handle is never determined. */
    public function getSize(): ?int { return null; }
    /**
     * Returns the current handle position.
     *
     * @throws RuntimeException when the stream is detached, or the position cannot be read
     */
    public function tell(): int { $pos = ftell($this->getResource()); if($pos===false) throw new RuntimeException('tell failed'); return $pos; }
    /**
     * Reports whether the handle has reached end-of-file.
     *
     * @throws RuntimeException when the stream is detached
     */
    public function eof(): bool { return feof($this->getResource()); }
    /**
     * Reports the handle's seekability as declared by its stream metadata.
     *
     * @throws RuntimeException when the stream is detached
     */
    public function isSeekable(): bool { return (bool) stream_get_meta_data($this->getResource())['seekable']; }
    /**
     * Moves the handle position.
     *
     * @throws RuntimeException when the stream is detached, or the seek fails
     */
    public function seek($offset, $whence = SEEK_SET): void { if(fseek($this->getResource(),$offset,$whence)!==0) throw new RuntimeException('seek failed'); }
    /**
     * Seeks back to the start of the stream.
     *
     * @throws RuntimeException when the stream is detached, or the seek fails
     */
    public function rewind(): void { $this->seek(0); }
    /**
     * Reports writability by inspecting the handle's open mode for a writing flag.
     *
     * @throws RuntimeException when the stream is detached
     */
    public function isWritable(): bool { $mode = stream_get_meta_data($this->getResource())['mode']; return strpbrk($mode,'waxc+')!==false; }
    /**
     * Writes to the handle at its current position and returns the byte count written.
     *
     * @throws RuntimeException when the stream is detached, not opened for writing, or the write fails
     */
    public function write($string): int { if(!$this->isWritable()) throw new RuntimeException('not writable'); $r=fwrite($this->getResource(),$string); if($r===false) throw new RuntimeException('write failed'); return $r; }
    /**
     * Reports readability by inspecting the handle's open mode for a reading flag.
     *
     * @throws RuntimeException when the stream is detached
     */
    public function isReadable(): bool { $mode = stream_get_meta_data($this->getResource())['mode']; return strpbrk($mode,'r+')!==false; }
    /**
     * Reads up to `$length` bytes from the current position; a non-positive length yields an empty string.
     *
     * @throws RuntimeException when the stream is detached, or the read fails
     */
    public function read($length): string { if($length <= 0) return ''; $d = fread($this->getResource(),$length); if($d===false) throw new RuntimeException('read failed'); return $d; }
    /**
     * Reads everything remaining from the current position onwards, without rewinding first.
     *
     * @throws RuntimeException when the stream is detached, or the read fails
     */
    public function getContents(): string { $c = stream_get_contents($this->getResource()); if($c===false) throw new RuntimeException('getContents failed'); return $c; }
    /**
     * Returns the handle's stream metadata, or a single entry from it.
     *
     * With no key the whole `stream_get_meta_data()` array is returned; with a key that the
     * metadata does not contain, null is returned.
     *
     * @throws RuntimeException when the stream is detached
     */
    public function getMetadata($key = null): mixed { $meta = stream_get_meta_data($this->getResource()); return $key===null? $meta:($meta[$key]??null); }
}
