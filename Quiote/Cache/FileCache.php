<?php
namespace Quiote\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Very small file-system PSR-16 cache (not for high concurrency, but fine as default replacement of legacy action/view cache).
 * Users can swap in a different implementation via DI later.
 *
 * The framework's own cache is {@see CacheManager}, which wraps symfony/cache;
 * this is a dependency-free fallback for an application that wants one.
 */
class FileCache implements CacheInterface
{
    /**
     * PSR-16 §1.3 reserves these in a key. A conforming implementation must
     * reject them rather than silently mangle the key.
     */
    private const string RESERVED_KEY_CHARACTERS = '{}()/\\@:';

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            // 0700, not 0777: the directory holds serialized application data
            // that get() feeds to unserialize(), and replacing a file is governed
            // by the *directory's* mode -- a world-writable cache directory lets
            // any local user swap a payload for one of their own choosing.
            @mkdir($directory, 0700, true);
        }
    }

    /**
     * @throws InvalidCacheKeyException When $key is not a legal PSR-16 key.
     */
    private function path(string $key): string
    {
        $this->assertValidKey($key);
        return rtrim($this->directory,'/').'/'.sha1($key).'.cache';
    }

    /**
     * PSR-16 requires an InvalidArgumentException for a key that is empty, not a
     * string, or contains a reserved character -- silently accepting one means
     * the same key is legal here and rejected by whichever implementation the
     * application swaps in later.
     *
     * @throws InvalidCacheKeyException
     */
    private function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidCacheKeyException('Cache key must not be an empty string.');
        }
        if (strpbrk($key, self::RESERVED_KEY_CHARACTERS) !== false) {
            throw new InvalidCacheKeyException(sprintf(
                'Cache key "%s" contains a character reserved by PSR-16 (%s).',
                $key,
                self::RESERVED_KEY_CHARACTERS,
            ));
        }
    }

    private function serialize(mixed $value, ?int $ttl): string
    {
        // A null TTL means "no expiry"; 0 or negative means already expired and
        // is handled by the caller, so it never reaches here as "no expiry".
        $expires = $ttl === null ? 0 : time() + $ttl;
        return $expires."\n".serialize($value);
    }

    /**
     * Decode a stored payload.
     *
     * Returns the sentinel rather than null on a miss so a legitimately cached
     * null is distinguishable from "not there" -- PSR-16 requires get() to
     * return a stored null as null, not as the caller's default.
     *
     * @param      string $payload The raw file contents.
     * @return     array{0: bool, 1: mixed} [hit, value]
     */
    private function unserialize(string $payload): array
    {
        $pos = strpos($payload,"\n");
        if ($pos === false) return [false, null];
        $exp = (int)substr($payload,0,$pos);
        if ($exp !== 0 && $exp < time()) return [false, null];
        $data = substr($payload,$pos+1);
        // allowed_classes: false -- the payload is attacker-controlled the moment
        // anything can write to the cache directory, and unserialize() without
        // this instantiates whatever class the payload names, running its
        // __wakeup()/__destruct(). Cached objects are not supported; scalars and
        // arrays are.
        if ($data === 'b:0;') return [true, false];
        $value = @unserialize($data, ['allowed_classes' => false]);
        if ($value === false) return [false, null];
        return [true, $value];
    }

    /**
     * Returns the cached value for $key, or $default.
     *
     * $default is returned when no file exists for the key, when the file
     * cannot be read, when the stored expiry has passed, or when the payload
     * fails to decode. A value that was genuinely stored as null is returned
     * as null rather than as $default.
     *
     * @throws InvalidCacheKeyException When $key is not a legal PSR-16 key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $f = $this->path($key);
        if (!is_file($f)) return $default;
        $payload = @file_get_contents($f);
        if ($payload === false) return $default;
        [$hit, $value] = $this->unserialize($payload);
        return $hit ? $value : $default;
    }
    /**
     * Writes $value to the cache file for $key.
     *
     * A \DateInterval TTL is converted to a number of seconds from now. A null
     * TTL stores the entry with no expiry; a zero or negative TTL deletes any
     * existing entry and reports success without writing anything. Returns
     * false if the file could not be written.
     *
     * @throws InvalidCacheKeyException When $key is not a legal PSR-16 key.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $ttlSeconds = $ttl instanceof \DateInterval ? (new \DateTimeImmutable())->add($ttl)->getTimestamp()-time() : $ttl;
        // PSR-16: a zero or negative TTL means the item is already expired, so it
        // must be deleted rather than stored. `$ttl ? … : 0` used to read 0 as
        // "no expiry", making set($k, $v, 0) cache forever.
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            $this->delete($key);
            return true;
        }
        return @file_put_contents($this->path($key), $this->serialize($value, $ttlSeconds)) !== false;
    }
    /**
     * Removes the cache file for $key.
     *
     * Reports success both when the file was unlinked and when no file was
     * there to begin with, so deleting a missing key is not an error.
     *
     * @throws InvalidCacheKeyException When $key is not a legal PSR-16 key.
     */
    public function delete(string $key): bool
    { return @unlink($this->path($key)) || !file_exists($this->path($key)); }
    /**
     * Unlinks every `*.cache` file in the cache directory.
     *
     * Only files this cache wrote are matched, so other content in the
     * directory is left alone. Returns false if any one file could not be
     * removed; the remaining files are still attempted.
     */
    public function clear(): bool
    {
        $ok = true; foreach (glob(rtrim($this->directory,'/').'/*.cache') ?: [] as $f) { if(!@unlink($f)) $ok=false; }
        return $ok;
    }
    /**
     * Yields each requested key paired with its cached value, or $default.
     *
     * The result is a generator: nothing is read from disk until the caller
     * iterates it, and each key is resolved through get().
     *
     * @param  iterable<string> $keys
     * @return iterable<string, mixed>
     * @throws InvalidCacheKeyException When one of the keys is not a legal PSR-16 key.
     */
    public function getMultiple($keys, mixed $default = null): iterable
    { foreach ($keys as $k) yield $k => $this->get($k,$default); }
    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple($values, null|int|\DateInterval $ttl = null): bool
    { $ok=true; foreach($values as $k=>$v) $ok = $this->set($k,$v,$ttl) && $ok; return $ok; }
    /**
     * Deletes every given key.
     *
     * All keys are attempted; the return value is false if any single delete
     * failed.
     *
     * @param  iterable<string> $keys
     * @throws InvalidCacheKeyException When one of the keys is not a legal PSR-16 key.
     */
    public function deleteMultiple($keys): bool
    { $ok=true; foreach($keys as $k) $ok=$this->delete($k)&&$ok; return $ok; }
    /**
     * Reports whether a live entry exists for $key.
     *
     * An entry whose file exists but is unreadable, expired or undecodable
     * counts as absent, so has() and get() can never disagree.
     *
     * @throws InvalidCacheKeyException When $key is not a legal PSR-16 key.
     */
    public function has(string $key): bool
    {
        $f=$this->path($key);
        if(!is_file($f)) return false;
        $payload=@file_get_contents($f);
        if($payload===false) return false;
        // Routed through the same decoder get() uses, so the two can never
        // disagree about whether an entry exists.
        [$hit] = $this->unserialize($payload);
        return $hit;
    }
}
