<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Eloquent\EloquentDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Database\PdoDatabase;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Integration tests for the Eloquent adapter. Skipped unless illuminate/database
 * is installed (it's a suggested, not required, dependency).
 */
class EloquentDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Capsule::class)) {
            $this->markTestSkipped('illuminate/database not installed');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }
    }

    public function testStandaloneSqliteRoundTrip(): void
    {
        $mgr = new DatabaseManager();
        $db = new EloquentDatabase();
        $db->initialize($mgr, ['driver' => 'sqlite', 'database' => ':memory:']);

        $capsule = $db->getCapsule();
        $this->assertInstanceOf(Capsule::class, $capsule);

        $conn = $db->getEloquentConnection();
        $conn->statement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $conn->table('t')->insert(['name' => 'quiote']);

        $this->assertSame('quiote', $conn->table('t')->where('id', 1)->value('name'));
        $this->assertTrue($db->ping());
        $this->assertSame($conn->getPdo(), $db->getPdo());
    }

    public function testLayerModeBorrowsPdoFromReferencedDatabase(): void
    {
        $under = new PdoDatabase();
        $orm = new EloquentDatabase();
        $mgr = new DatabaseManager();
        $ref = new ReflectionProperty($mgr, 'databases');
        $ref->setValue($mgr, ['under' => $under, 'orm' => $orm]);

        $under->initialize($mgr, ['dsn' => 'sqlite::memory:']);
        $orm->initialize($mgr, ['connection' => 'under', 'driver' => 'sqlite']);

        // The Eloquent connection should be driving the very PDO the PdoDatabase opened.
        $this->assertSame($under->getConnection(), $orm->getEloquentConnection()->getPdo());
    }
}
