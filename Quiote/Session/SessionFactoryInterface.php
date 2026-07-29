<?php

declare(strict_types=1);

namespace Quiote\Session;

use Quiote\Context;

/**
 * Builds the persistence backend for the `session` factory slot.
 *
 * The slot needs this indirection because the codegen's instantiating branch
 * emits `new $class(); $obj->initialize($context, $params)`, and no
 * {@see SessionPersistenceInterface} implementation has that shape --
 * FileSessionPersistence takes a directory, PdoSessionPersistence takes a PDO
 * connection. Retrofitting an initialize() onto those value objects purely to
 * satisfy a config template would be backwards; a small factory per backend is
 * the honest seam.
 *
 * The configured parameters reach both this method and SessionManager's
 * constructor, so cookie settings (`cookie_name`, `session_cookie_lifetime`,
 * `session_cookie_secure`, `session_cookie_samesite`,
 * `session_migration_grace_seconds`) and backend settings live in one place.
 *
 * @since      2.2.0
 */
interface SessionFactoryInterface
{
    /**
     * @param array<string, mixed> $parameters The slot's configured parameters.
     */
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface;
}
