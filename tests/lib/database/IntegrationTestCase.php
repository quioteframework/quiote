<?php

namespace Quiote\Test\Database;

use PHPUnit\Framework\TestCase;
use Quiote\Database\Database;
use Quiote\Database\DatabaseManager;

/**
 * Base class for database integration tests. Skips the whole class when Docker is
 * unavailable, and provides a helper to build a {@see DatabaseManager} populated
 * with named {@see Database} instances (mirroring what the compiled config would
 * produce) so adapters can resolve each other by name.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (!DatabaseContainers::dockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }
    }

    /**
     * Build a DatabaseManager holding the given named databases and initialise
     * each with its parameters.
     *
     * @param array<string, array{0: Database, 1: array<string,mixed>}> $databases
     *        name => [databaseInstance, parameters]
     */
    protected function makeManager(array $databases): DatabaseManager
    {
        $manager = new DatabaseManager();

        $instances = [];
        foreach ($databases as $name => [$db]) {
            $instances[$name] = $db;
        }

        $ref = new \ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, $instances);

        foreach ($databases as [$db, $params]) {
            $db->initialize($manager, $params);
        }

        return $manager;
    }
}
