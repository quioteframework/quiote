<?php

use PHPUnit\Framework\TestCase;
use Quiote\Database\PdoDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;

class PdoDatabaseTest extends TestCase
{
    private function makeManager(): DatabaseManager
    {
        // Minimal stub using reflection to inject database mapping
        // We'll not call initialize(); we only need name resolution.
        return new DatabaseManager();
    }

    public function testConnectSqliteMemoryWithInitQueriesAndAttributes(): void
    {
        if(!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available in test environment');
        }
        $mgr = $this->makeManager();
        $db = new PdoDatabase();
        $params = [
            'dsn' => 'sqlite::memory:',
            'init_queries' => [ 'PRAGMA foreign_keys = ON' ],
            'attributes' => [ 'PDO::ATTR_TIMEOUT' => 2 ],
            'options' => [ 'PDO::ATTR_PERSISTENT' => false ],
        ];
        $db->initialize($mgr, $params);
        $pdo = $db->getConnection();
        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        // Ensure foreign_keys pragma took effect
        $stmt = $pdo->query('PRAGMA foreign_keys');
        $this->assertNotFalse($stmt);
        $fk = $stmt->fetchColumn();
        $this->assertEquals(1, (int)$fk);
        $db->shutdown();
        $this->assertNull((new ReflectionProperty($db, 'connection'))->getValue($db));
    }

    public function testMissingDsnThrows(): void
    {
        $this->expectException(DatabaseException::class);
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ /* no dsn */ ]);
        // getConnection triggers connect which should throw
        $db->getConnection();
    }

    public function testMysqlUnsafeSetNamesWarning(): void
    {
        $this->expectException(DatabaseException::class);
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [
            'dsn' => 'mysql:host=localhost;dbname=test',
            'init_queries' => [ 'SET NAMES utf8' ],
            'warn_mysql_charset' => true,
        ]);
    }

    public function testGetPdoReturnsTheSamePdoInstance(): void
    {
        if(!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available in test environment');
        }
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ 'dsn' => 'sqlite::memory:' ]);
        $this->assertSame($db->getConnection(), $db->getPdo());
    }

    public function testGetPdoThrowsIfConnectionIsNotActuallyPdo(): void
    {
        // connect() always sets a real PDO, so force a corrupted state via
        // reflection to exercise the defensive failure path.
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ 'dsn' => 'sqlite::memory:' ]);
        (new ReflectionProperty($db, 'connection'))->setValue($db, new stdClass());

        $this->expectException(DatabaseException::class);
        $db->getPdo();
    }

    public function testShutdownDisconnects(): void
    {
        if(!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available in test environment');
        }
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ 'dsn' => 'sqlite::memory:' ]);
        $pdo = $db->getConnection();
        $this->assertInstanceOf(PDO::class, $pdo);
        $db->shutdown();
        // After shutdown a new getConnection should create a fresh PDO
        $new = $db->getConnection();
        $this->assertInstanceOf(PDO::class, $new);
        $this->assertNotSame($pdo, $new);
    }

    public function testPingReturnsTrueWithoutConnection(): void
    {
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ 'dsn' => 'sqlite::memory:' ]);
        $this->assertTrue($db->ping());
    }

    public function testPingReturnsTrueForFreshlyUsedConnectionWithoutRoundTrip(): void
    {
        if(!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available in test environment');
        }
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ 'dsn' => 'sqlite::memory:' ]);
        $db->getConnection(); // stamps lastUsedAt

        // Corrupt the connection so an actual round trip would fail; ping()
        // must still return true because the idle threshold hasn't elapsed,
        // proving the round trip was skipped rather than accidentally passing.
        (new ReflectionProperty($db, 'connection'))->setValue($db, new stdClass());

        $this->assertTrue($db->ping());
    }

    public function testPingActuallyProbesAfterIdleThresholdElapsed(): void
    {
        if(!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available in test environment');
        }
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ 'dsn' => 'sqlite::memory:' ]);
        $db->getConnection();

        // Simulate having gone idle past the threshold.
        (new ReflectionProperty($db, 'lastUsedAt'))->setValue($db, microtime(true) - 3600);

        $this->assertTrue($db->ping());
    }

    public function testPingReturnsFalseAndNullsConnectionAfterIdleThresholdWhenBroken(): void
    {
        $db = new PdoDatabase();
        $db->initialize($this->makeManager(), [ 'dsn' => 'sqlite::memory:' ]);
        $db->getConnection();

        // Force the connection into a broken state (a stand-in that fails the
        // same way a dead PDO connection would) and mark it as stale.
        $broken = new class {
            public function query(string $sql): never
            {
                throw new PDOException('gone away');
            }
        };
        (new ReflectionProperty($db, 'connection'))->setValue($db, $broken);
        (new ReflectionProperty($db, 'lastUsedAt'))->setValue($db, microtime(true) - 3600);

        $this->assertFalse($db->ping());
        $this->assertNull((new ReflectionProperty($db, 'connection'))->getValue($db));
    }
}
