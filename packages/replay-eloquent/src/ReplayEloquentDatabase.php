<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Eloquent;

use Quiote\Database\Adapter\Eloquent\EloquentDatabase;

/**
 * {@see EloquentDatabase}, with {@see EloquentQueryRecorder} attached to the
 * Illuminate connection it builds. Registered under the `eloquent` driver
 * alias by {@see ReplayEloquentPlugin} in place of the plain
 * {@see EloquentDatabase} `quioteframework/db-eloquent`'s own `EloquentPlugin`
 * registers.
 *
 * Attaching after `parent::connect()` (rather than overriding the whole
 * method) is safe here, unlike Doctrine's DBAL middleware: `attach()` only
 * calls `Connection::listen()`, which has nothing to do before the
 * connection object already exists.
 */
final class ReplayEloquentDatabase extends EloquentDatabase
{
    #[\Override]
    protected function connect()
    {
        parent::connect();

        (new EloquentQueryRecorder())->attach($this->getEloquentConnection());
    }
}
