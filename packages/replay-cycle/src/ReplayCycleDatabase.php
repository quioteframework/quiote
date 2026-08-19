<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Cycle;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
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
 *
 * `setLogger()` is a whole-value assignment, so the recording logger
 * {@see CycleRecordingLogger::wrapping()} composes with whatever the
 * application had installed rather than replacing it. Installing this package
 * must not silently end an application's own Cycle query logging -- the
 * Eloquent adapter next door already gets this right by adding a listener
 * alongside an existing dispatcher, and the two should not disagree.
 */
final class ReplayCycleDatabase extends CycleDatabase
{
    #[\Override]
    protected function connect()
    {
        parent::connect();

        if ($this->resource instanceof LoggerAwareInterface) {
            $this->resource->setLogger(CycleRecordingLogger::wrapping($this->existingLogger()));
        }
    }

    /**
     * Whatever logger the application already had on the manager, when it can be read.
     *
     * `LoggerAwareInterface` is write-only by design -- there is no `getLogger()` -- and
     * `DatabaseManager` exposes no accessor either, so this reads the property it assigns to when
     * one is declared and returns null otherwise. A null means "nothing to forward to", which is
     * the common case (`CycleDatabase::connect()` never sets one) and is not an error.
     */
    private function existingLogger(): ?LoggerInterface
    {
        $manager = $this->resource;
        if (!is_object($manager)) {
            return null;
        }

        try {
            $property = new \ReflectionProperty($manager, 'logger');
        } catch (\ReflectionException) {
            // No such property on this version of DatabaseManager: nothing to preserve, and not
            // something to fail a connection over.
            return null;
        }

        $existing = $property->isInitialized($manager) ? $property->getValue($manager) : null;

        return $existing instanceof LoggerInterface ? $existing : null;
    }
}
