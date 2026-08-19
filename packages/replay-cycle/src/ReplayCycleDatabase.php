<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Cycle;

use Psr\Log\LoggerAwareInterface;
use Quiote\Database\Adapter\Cycle\CycleDatabase;

/**
 * {@see CycleDatabase}, with {@see CycleRecordingLogger} installed on the
 * `Cycle\Database\DatabaseManager` it builds. Registered under the `cycle`
 * driver alias by {@see ReplayCyclePlugin} in place of the plain
 * {@see CycleDatabase} `quioteframework/db-cycle`'s own `CyclePlugin`
 * registers.
 *
 * Reads `$this->resource` directly rather than `getCycleDatabaseManager()`
 * (which only promises `Cycle\Database\DatabaseProviderInterface`, with no
 * `setLogger()`): the concrete `Cycle\Database\DatabaseManager` `connect()`
 * builds also implements PSR-3's {@see LoggerAwareInterface}, which is the
 * contract this checks against instead of the concrete class.
 *
 * Calling `setLogger()` after `parent::connect()` (rather than duplicating
 * that method's body) is safe: `Cycle\Database\DatabaseManager::setLogger()`
 * re-applies the logger to every driver already initialized as well as every
 * one created afterward, and `connect()` itself never resolves a driver --
 * only `new Cycle\Database\DatabaseManager(...)`/`new Cycle\ORM\ORM(...)`,
 * neither of which touches an actual connection.
 */
final class ReplayCycleDatabase extends CycleDatabase
{
    #[\Override]
    protected function connect()
    {
        parent::connect();

        if ($this->resource instanceof LoggerAwareInterface) {
            $this->resource->setLogger(new CycleRecordingLogger());
        }
    }
}
