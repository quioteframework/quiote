<?php

declare(strict_types=1);

namespace Quiote\Execution\Slot;

use Quiote\Cache\CacheManager;
use Quiote\Logging\CategoryLogger;

/**
 * Reading and writing a slot's rendered content to the shared cache.
 *
 * Two things here are not a plain get/set.
 *
 * The key has to change whenever anything the render depended on changes, so it
 * folds in the module, action, output type, a digest of the slot's arguments,
 * and -- when the action declares cache tags -- the current version of each
 * tag's namespace. Bumping a tag's version therefore invalidates every slot
 * carrying it without touching the cache itself.
 *
 * The payload carries its own monotonic expiry stamp, checked independently of
 * the backend's wall-clock expiry, so a cache whose clock disagrees with this
 * process cannot serve content the slot already considers stale.
 *
 * A cache that cannot be read or written is a miss, never an error: the page is
 * still correct, only slower, and that is not worth failing a request over. It
 * is reported, because a cache silently doing nothing is a performance cliff
 * nobody notices.
 */
final readonly class SlotCache
{
    /** Marks a payload as carrying its own expiry stamp. */
    private const string MONO_TTL_MARKER = "\x00SCTTL1\x00";

    public function __construct(private CategoryLogger $logger, private string $slotKey) {}

    /**
     * Composes the cache key for one slot render.
     *
     * @param array<string, mixed> $parameters
     * @param array<int, mixed> $tags
     */
    public function keyFor(string $module, string $action, string $outputType, array $parameters, array $tags): string
    {
        // Composed through CacheManager::key() rather than concatenated with ':',
        // which PSR-16 reserves.
        $parts = ['slot', strtolower($module), strtolower($action), $outputType];

        if ($tags !== []) {
            $versions = [];
            foreach ($tags as $tag) {
                if (!is_scalar($tag) && !$tag instanceof \Stringable) {
                    // A tag that cannot be named cannot version anything.
                    continue;
                }
                try {
                    $versions[] = (string) CacheManager::getNamespaceVersion(
                        CacheManager::slotTagNamespace((string) $tag)
                    );
                } catch (\Throwable) {
                    // An unreadable version means "assume the oldest", which can only cause a
                    // miss, never a stale hit.
                    $versions[] = '0';
                }
            }
            $parts[] = implode('-', $versions);
        }

        $parts[] = $this->digestOf($parameters);

        return CacheManager::key(...$parts);
    }

    /**
     * A digest of the slot's arguments.
     *
     * json_encode() can fail on malformed UTF-8 or a resource, and hashing the
     * false verbatim would collapse every failing call onto one key -- serving
     * unrelated content. A per-call unique value is used instead, so the render
     * simply goes uncached.
     *
     * @param array<string, mixed> $parameters
     */
    private function digestOf(array $parameters): string
    {
        $encoded = json_encode($parameters);

        return $encoded !== false ? md5($encoded) : 'uncacheable-' . bin2hex(random_bytes(8));
    }

    /** The cached content, or null on a miss, an expired stamp, or an unreadable cache. */
    public function read(string $cacheKey): ?string
    {
        try {
            return $this->decode(CacheManager::getCache()->get($cacheKey));
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[SlotDisp] slot cache read failed for key=' . $this->slotKey . '; rendering uncached: '
                . $e->getMessage()
            );

            return null;
        }
    }

    /** Stores the rendered content. A failure leaves the page correct and only slower. */
    public function write(string $cacheKey, string $content, ?int $ttl): void
    {
        try {
            CacheManager::getCache()->set($cacheKey, $this->encode($content, $ttl), $ttl ?: null);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[SlotDisp] slot cache write failed for key=' . $this->slotKey . '; the slot re-renders next time: '
                . $e->getMessage()
            );
        }
    }

    /**
     * Wraps content with its expiry stamp when an explicit TTL is given.
     *
     * Without one the backend's own default expiry governs and the content is
     * stored raw. Content that will not survive json_encode is also stored raw
     * rather than dropped: losing the entry is worse than losing its stamp.
     */
    public function encode(string $content, ?int $ttl): string
    {
        if ($ttl === null || $ttl <= 0) {
            return $content;
        }

        $encoded = json_encode(['c' => $content, 'e' => hrtime(true) + ($ttl * 1_000_000_000)]);

        return $encoded === false ? $content : self::MONO_TTL_MARKER . $encoded;
    }

    /**
     * Unwraps a stored payload, or null when it is a miss or has expired.
     *
     * An unwrapped string is always a hit: it was stored without a TTL, so its
     * freshness is the backend's business.
     */
    public function decode(mixed $cached): ?string
    {
        if (!is_string($cached)) {
            return null;
        }

        if (!str_starts_with($cached, self::MONO_TTL_MARKER)) {
            return $cached;
        }

        $decoded = json_decode(substr($cached, strlen(self::MONO_TTL_MARKER)), true);

        if (!is_array($decoded) || !isset($decoded['c'], $decoded['e'])
            || !is_string($decoded['c']) || !is_int($decoded['e'])) {
            return null;
        }

        // Expired by our own clock, whatever the backend still thinks.
        return hrtime(true) > $decoded['e'] ? null : $decoded['c'];
    }
}
