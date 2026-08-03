<?php

declare(strict_types=1);

namespace Quiote\Cache;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * Thrown for a cache key PSR-16 does not permit: empty, or containing one of the
 * characters reserved by PSR-16 §1.3 (`{}()/\@:`).
 *
 * @since      3.1.1
 */
class InvalidCacheKeyException extends \InvalidArgumentException implements InvalidArgumentException
{
}
