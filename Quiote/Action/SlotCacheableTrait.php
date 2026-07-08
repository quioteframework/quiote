<?php
namespace Quiote\Action;
/**
 * Optional trait for actions that want to customize slot caching TTL and tags.
 */
trait SlotCacheableTrait
{
    /** Return TTL in seconds (null or <=0 for default/no explicit TTL). */
    public function slotCacheTtlSeconds(): ?int { return null; }
    /**
     * Return an array of tag identifiers (strings) used for versioned slot cache keys.
     * @param array<string, mixed> $parameters
     * @return array<int, string>
     */
    public function slotCacheTags(array $parameters = []): array { return []; }
}
