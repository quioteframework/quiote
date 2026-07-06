<?php

use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\ORM\ORM;
use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Cycle\CycleDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;

/**
 * Unit test for CycleDatabase::getPdo() — always unsupported, since
 * cycle/database never exposes its driver's PDO/PDOInterface publicly.
 */
class CycleDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(ORM::class)) {
            $this->markTestSkipped('cycle/orm not installed');
        }
    }

    public function testGetPdoAlwaysThrows(): void
    {
        $cycleConfig = [
            'default'     => 'default',
            'databases'   => ['default' => ['connection' => 'sqlite']],
            'connections' => [
                'sqlite' => new SQLiteDriverConfig(connection: new MemoryConnectionConfig()),
            ],
        ];

        $db = new CycleDatabase();
        $db->initialize(new DatabaseManager(), [
            'cycle'  => $cycleConfig,
            'schema' => [],
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/does not expose a raw PDO connection/');
        $db->getPdo();
    }
}
