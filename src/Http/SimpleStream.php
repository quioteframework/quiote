<?php
namespace Agavi\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

class SimpleStream implements StreamInterface
{
    private $resource;

    public function __construct($resource)
    {
        if(!is_resource($resource)) {
            $resource = fopen('php://temp','r+');
            if($resource === false) throw new RuntimeException('Cannot create temp stream');
        }
        $this->resource = $resource;
    }

    public static function fromString(string $content): self
    {
        $h = fopen('php://temp','r+');
        fwrite($h,$content);
        rewind($h);
        return new self($h);
    }

    public function __toString(): string
    { try { $this->seek(0); return stream_get_contents($this->resource) ?: ''; } catch(\Throwable) { return ''; } }
    public function close(): void { if(is_resource($this->resource)) fclose($this->resource); }
    public function detach() { $r=$this->resource; $this->resource=null; return $r; }
    public function getSize(): ?int { $stats = stream_get_meta_data($this->resource); return null; }
    public function tell(): int { $pos = ftell($this->resource); if($pos===false) throw new RuntimeException('tell failed'); return $pos; }
    public function eof(): bool { return feof($this->resource); }
    public function isSeekable(): bool { return (bool) stream_get_meta_data($this->resource)['seekable']; }
    public function seek($offset, $whence = SEEK_SET): void { if(fseek($this->resource,$offset,$whence)!==0) throw new RuntimeException('seek failed'); }
    public function rewind(): void { $this->seek(0); }
    public function isWritable(): bool { $mode = stream_get_meta_data($this->resource)['mode']; return strpbrk($mode,'waxc+')!==false; }
    public function write($string): int { if(!$this->isWritable()) throw new RuntimeException('not writable'); $r=fwrite($this->resource,$string); if($r===false) throw new RuntimeException('write failed'); return $r; }
    public function isReadable(): bool { $mode = stream_get_meta_data($this->resource)['mode']; return strpbrk($mode,'r+')!==false; }
    public function read($length): string { $d = fread($this->resource,$length); if($d===false) throw new RuntimeException('read failed'); return $d; }
    public function getContents(): string { $c = stream_get_contents($this->resource); if($c===false) throw new RuntimeException('getContents failed'); return $c; }
    public function getMetadata($key = null): mixed { $meta = stream_get_meta_data($this->resource); return $key===null? $meta:($meta[$key]??null); }
}
