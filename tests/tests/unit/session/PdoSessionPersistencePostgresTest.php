<?php

use PHPUnit\Framework\TestCase;
use Quiote\Session\PdoSessionPersistence;
use Quiote\Test\Database\DatabaseContainers;

/**
 * Regression coverage for a Postgres-specific bug LegacyPdoSessionPersistenceTest
 * (SQLite-backed) cannot see: pdo_pgsql returns a `bytea` column as a stream
 * resource from fetchColumn(), not a string. load()'s `!is_string($blob)` guard
 * treated every resource as "not found", so a session that genuinely existed --
 * correct id, correct cookie, correct row -- was never loaded back. Every request
 * against Postgres silently started a fresh anonymous session instead.
 */
class PdoSessionPersistencePostgresTest extends TestCase
{
    private PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        if (!DatabaseContainers::dockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }
        if (!DatabaseContainers::pdoDriver('pgsql')) {
            $this->markTestSkipped('pdo_pgsql driver not available in test environment');
        }

        $this->pdo = self::connect();
        $this->pdo->exec('DROP TABLE IF EXISTS session');
        $this->pdo->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data BYTEA NOT NULL, sess_time TIMESTAMP NOT NULL)');
    }

    public function testSaveThenLoadRoundTripsDataOnPostgres(): void
    {
        $persistence = new PdoSessionPersistence($this->pdo);

        $persistence->save('sid1', ['user_id' => 42, 'name' => 'Ada']);

        $this->assertSame(['user_id' => 42, 'name' => 'Ada'], $persistence->load('sid1'));
    }

    public function testLoadFindsARowWrittenByAnotherConnection(): void
    {
        // The bug only reproduces on a row that genuinely exists as far as SQL is
        // concerned -- writing it through a *second* connection, exactly like the
        // browser's login POST and the following page load are two separate
        // requests against the same persisted row, rules out any per-connection
        // fetch-mode quirk explaining a false negative.
        $writer = new PdoSessionPersistence($this->pdo);
        $writer->save('sid-cross-conn', ['authenticated' => true]);

        $reader = self::connect();

        $this->assertSame(
            ['authenticated' => true],
            (new PdoSessionPersistence($reader))->load('sid-cross-conn'),
        );
    }

    public function testLoadReturnsNullForMissingSessionOnPostgres(): void
    {
        $persistence = new PdoSessionPersistence($this->pdo);

        $this->assertNull($persistence->load('does-not-exist'));
    }

    /** Opens a connection to the container's Postgres, in exception mode. */
    private static function connect(): PDO
    {
        $info = DatabaseContainers::postgres();
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $info['host'], $info['port'], $info['database']),
            $info['username'],
            $info['password'],
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
