<?php
namespace Agavi\Cache;

use Psr\SimpleCache\CacheInterface as PsrSimpleCacheInterface;

/**
 * Framework-facing cache interface; extends PSR-16 for flexibility.
 */
interface AgaviCacheInterface extends PsrSimpleCacheInterface {}
