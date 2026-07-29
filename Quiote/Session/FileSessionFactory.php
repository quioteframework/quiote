<?php

declare(strict_types=1);

namespace Quiote\Session;

use Quiote\Config\Config;
use Quiote\Context;

/**
 * The default `session` slot factory: file-backed, zero dependencies, no
 * database required. Suitable for a single host or any deployment with a shared
 * filesystem; for multiple hosts without one, configure a PDO, Redis or
 * object-storage backed factory instead.
 *
 * Parameters: `dir` (defaults to `core.app_dir`/cache/sessions), plus whatever
 * {@see FileSessionPersistence} accepts (`idle_ttl`, `gc_probability`,
 * `gc_divisor`).
 *
 * @since      2.2.0
 */
final class FileSessionFactory implements SessionFactoryInterface
{
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        $dir = $parameters['dir'] ?? null;
        if (!is_string($dir) || $dir === '') {
            $dir = Config::getString('core.app_dir', sys_get_temp_dir()) . '/cache/sessions';
        }

        return new FileSessionPersistence($dir, $parameters);
    }
}
