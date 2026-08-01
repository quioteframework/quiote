<?php
namespace Quiote\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Minimal action+view result cache. Keyed by module:action:outputType, plus
 * the current locale and per-user fingerprint when given -- a cached
 * response is specific to the locale it was rendered in, so two requests
 * differing only by locale must not collide on the same entry.
 * Stores: view_module, view_name, action_attributes (optional), rendered response content,
 *          plus (migration) execution descriptor/state metadata when provided.
 */
class ActionViewCache
{
    public function __construct(private readonly CacheInterface $cache, private readonly ?int $defaultTtlSeconds = 300) {}

    private function key(string $module, string $action, string $outputType, ?string $fingerprint = null, ?string $locale = null): string
    {
        // Compose key using module + action specific namespace versions for targeted invalidation.
        $modVer = CacheManager::getNamespaceVersion(CacheManager::key('avmod', $module));
        $actVer = CacheManager::getNamespaceVersion(CacheManager::key('avact', $module, $action));
        $parts = ['av', (string)$modVer, (string)$actVer, $module, $action, $outputType];
        if ($locale) {
            $parts[] = 'loc';
            $parts[] = $locale;
        }
        if ($fingerprint) {
            $parts[] = 'u';
            $parts[] = $fingerprint;
        }
        return CacheManager::key(...$parts);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $module, string $action, string $outputType, ?string $fingerprint = null, ?string $locale = null): ?array
    {
        // PSR-16 get() is typed mixed: a cache holding something other than a
        // payload array (a stale entry from an older format, or a key collision
        // with app code sharing the pool) reads as a miss rather than being
        // handed to a caller expecting an array.
        $cached = $this->cache->get($this->key($module, $action, $outputType, $fingerprint, $locale));
        if (!is_array($cached)) {
            return null;
        }
        // Payload keys are strings by contract (see set()); re-key so a stray
        // int-keyed entry from an older serialization can't desync consumers.
        $payload = [];
        foreach ($cached as $name => $value) {
            $payload[(string)$name] = $value;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function set(string $module, string $action, string $outputType, array $payload, ?int $ttlSeconds = null, ?string $fingerprint = null, ?string $locale = null): void
    {
        // Normalize new descriptor/state keys to a sub-structure to avoid collisions
        if(isset($payload['descriptor']) || isset($payload['state'])) {
            $payload['_meta_version'] = 1;
        }
        $this->cache->set($this->key($module,$action,$outputType,$fingerprint,$locale), $payload, $ttlSeconds ?? $this->defaultTtlSeconds);
    }

    public function delete(string $module, string $action, string $outputType, ?string $fingerprint = null, ?string $locale = null): void
    { $this->cache->delete($this->key($module,$action,$outputType,$fingerprint,$locale)); }

    public function invalidateModule(string $module): void
    { CacheManager::invalidateModule($module); }

    public function invalidateAction(string $module, string $action): void
    { CacheManager::invalidateAction($module, $action); }
}
