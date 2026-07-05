<?php
namespace Quiote\Cache;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Very small file-system PSR-16 cache (not for high concurrency, but fine as default replacement of legacy action/view cache).
 * Users can swap in a different implementation via DI later.
 */
class FileCache implements CacheInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
    }

    private function path(string $key): string { return rtrim($this->directory,'/').'/'.sha1($key).'.cache'; }

    private function serialize(mixed $value, ?int $ttl): string
    {
        $expires = $ttl ? (time()+$ttl) : 0;
        return $expires."\n".serialize($value);
    }
    private function unserialize(string $payload): mixed
    {
        $pos = strpos($payload,"\n");
        $exp = (int)substr($payload,0,$pos);
        if ($exp !== 0 && $exp < time()) return null;
        $data = substr($payload,$pos+1);
        return @unserialize($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $f = $this->path($key);
        if (!is_file($f)) return $default;
        $val = $this->unserialize(@file_get_contents($f) ?: '0\nN;');
        return $val ?? $default;
    }
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $ttlSeconds = $ttl instanceof \DateInterval ? (new \DateTimeImmutable())->add($ttl)->getTimestamp()-time() : $ttl;
        return (bool)@file_put_contents($this->path($key), $this->serialize($value, $ttlSeconds));
    }
    public function delete(string $key): bool
    { return @unlink($this->path($key)) || !file_exists($this->path($key)); }
    public function clear(): bool
    {
        $ok = true; foreach (glob(rtrim($this->directory,'/').'/*.cache') as $f) { if(!@unlink($f)) $ok=false; }
        return $ok;
    }
    public function getMultiple($keys, mixed $default = null): iterable
    { foreach ($keys as $k) yield $k => $this->get($k,$default); }
    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple($values, null|int|\DateInterval $ttl = null): bool
    { $ok=true; foreach($values as $k=>$v) $ok = $this->set($k,$v,$ttl) && $ok; return $ok; }
    public function deleteMultiple($keys): bool
    { $ok=true; foreach($keys as $k) $ok=$this->delete($k)&&$ok; return $ok; }
    public function has(string $key): bool
    { $f=$this->path($key); if(!is_file($f)) return false; $payload=@file_get_contents($f); if($payload===false) return false; $pos=strpos($payload,"\n"); $exp=(int)substr($payload,0,$pos); return $exp===0 || $exp>=time(); }
}
