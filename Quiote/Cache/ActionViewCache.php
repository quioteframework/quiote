<?php
namespace Quiote\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Minimal action+view result cache. Keyed by module:action:outputType.
 * Stores: view_module, view_name, action_attributes (optional), rendered response content,
 *          plus (migration) execution descriptor/state metadata when provided.
 */
class ActionViewCache
{
    public function __construct(private readonly CacheInterface $cache, private readonly ?int $defaultTtlSeconds = 300) {}

    private function key(string $module, string $action, string $outputType, ?string $fingerprint = null): string
    {
        // Compose key using module + action specific namespace versions for targeted invalidation.
        $modVer = CacheManager::getNamespaceVersion('avmod:' . $module);
        $actVer = CacheManager::getNamespaceVersion('avact:' . $module . ':' . $action);
        $fpPart = $fingerprint ? (':u:' . $fingerprint) : '';
        return 'av:' . $modVer . ':' . $actVer . ':' . $module . ':' . $action . ':' . $outputType . $fpPart;
    }

    public function get(string $module, string $action, string $outputType, ?string $fingerprint = null): ?array
    { return $this->cache->get($this->key($module,$action,$outputType,$fingerprint)); }

    public function set(string $module, string $action, string $outputType, array $payload, ?int $ttlSeconds = null, ?string $fingerprint = null): void
    {
        // Normalize new descriptor/state keys to a sub-structure to avoid collisions
        if(isset($payload['descriptor']) || isset($payload['state'])) {
            $payload['_meta_version'] = 1;
        }
        $this->cache->set($this->key($module,$action,$outputType,$fingerprint), $payload, $ttlSeconds ?? $this->defaultTtlSeconds);
    }

    public function delete(string $module, string $action, string $outputType, ?string $fingerprint = null): void
    { $this->cache->delete($this->key($module,$action,$outputType,$fingerprint)); }

    public function invalidateModule(string $module): void
    { CacheManager::invalidateModule($module); }

    public function invalidateAction(string $module, string $action): void
    { CacheManager::invalidateAction($module, $action); }
}
