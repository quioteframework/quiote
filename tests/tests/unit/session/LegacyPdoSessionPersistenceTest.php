<?php

use PHPUnit\Framework\TestCase;
use Quiote\Session\PdoSessionPersistence;

/**
 * Happy + failure path coverage for PdoSessionPersistence, plus the two perf
 * fixes: cached prepared statements (instead of re-preparing per call) and
 * skipping the igbinary_unserialize() attempt for JSON-shaped blobs.
 */
class LegacyPdoSessionPersistenceTest extends TestCase
{
    private PDO $pdo;
    private PdoSessionPersistence $persistence;

    #[\Override]
    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available in test environment');
        }
        $this->pdo = new Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // PdoSessionPersistence's INSERT targets Postgres-style NOW() +
        // ON CONFLICT; sqlite supports the ON CONFLICT upsert syntax but has
        // no built-in NOW(), so register one rather than changing the
        // production SQL just to accommodate the test driver.
        $this->pdo->createFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        $this->pdo->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time TIMESTAMP NOT NULL)');
        $this->persistence = new PdoSessionPersistence($this->pdo);
    }

    public function testLoadReturnsNullForMissingSession(): void
    {
        $this->assertNull($this->persistence->load('does-not-exist'));
    }

    public function testSaveThenLoadRoundTripsData(): void
    {
        $this->persistence->save('sid1', ['user_id' => 42, 'name' => 'Ada']);

        $this->assertSame(['user_id' => 42, 'name' => 'Ada'], $this->persistence->load('sid1'));
    }

    public function testSaveUpsertsOnRepeatSave(): void
    {
        $this->persistence->save('sid1', ['count' => 1]);
        $this->persistence->save('sid1', ['count' => 2]);

        $this->assertSame(['count' => 2], $this->persistence->load('sid1'));
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM session');
        $this->assertNotFalse($stmt);
        $rowCount = (int) $stmt->fetchColumn();
        $this->assertSame(1, $rowCount, 'a repeat save() must update, not insert a second row');
    }

    public function testDeleteRemovesTheSession(): void
    {
        $this->persistence->save('sid1', ['a' => 1]);
        $this->persistence->delete('sid1');

        $this->assertNull($this->persistence->load('sid1'));
    }

    public function testDeleteOfNonExistentSessionDoesNotThrow(): void
    {
        $this->persistence->delete('never-existed');
        $this->addToAssertionCount(1);
    }

    public function testLoadReturnsNullForEmptyStoredBlob(): void
    {
        $this->pdo->exec("INSERT INTO session (sess_id, sess_data, sess_time) VALUES ('sid-empty', '', '2024-01-01')");
        $this->assertNull($this->persistence->load('sid-empty'));
    }

    public function testLoadReturnsNullForGarbageBlobThatIsNeitherJsonNorIgbinary(): void
    {
        $this->pdo->exec("INSERT INTO session (sess_id, sess_data, sess_time) VALUES ('sid-garbage', 'not-json-not-igbinary', '2024-01-01')");
        $this->assertNull($this->persistence->load('sid-garbage'));
    }

    public function testLoadDecodesAJsonBlobWrittenDirectly(): void
    {
        // Simulates a row written before igbinary was enabled (or by another
        // process): plain JSON, no igbinary attempt should be needed to read it.
        $json = json_encode(['foo' => 'bar', 'n' => 7]);
        $this->pdo->exec("INSERT INTO session (sess_id, sess_data, sess_time) VALUES ('sid-json', " . $this->pdo->quote((string) $json) . ", '2024-01-01')");

        $this->assertSame(['foo' => 'bar', 'n' => 7], $this->persistence->load('sid-json'));
    }

    public function testPreparedStatementsAreCachedAndReusedAcrossCalls(): void
    {
        $this->persistence->save('sid1', ['a' => 1]);
        $this->persistence->load('sid1');
        $this->persistence->save('sid2', ['b' => 2]);
        $this->persistence->load('sid2');

        $loadProp = new ReflectionProperty(PdoSessionPersistence::class, 'loadStmt');
        $saveProp = new ReflectionProperty(PdoSessionPersistence::class, 'saveStmt');
        $loadStmt = $loadProp->getValue($this->persistence);
        $saveStmt = $saveProp->getValue($this->persistence);

        $this->assertInstanceOf(PDOStatement::class, $loadStmt);
        $this->assertInstanceOf(PDOStatement::class, $saveStmt);

        // Call again and verify the SAME statement object instances are reused.
        $this->persistence->load('sid1');
        $this->persistence->save('sid1', ['a' => 2]);
        $this->assertSame($loadStmt, $loadProp->getValue($this->persistence));
        $this->assertSame($saveStmt, $saveProp->getValue($this->persistence));
    }

    public function testDeleteStatementIsCachedAndReusedAcrossCalls(): void
    {
        $this->persistence->delete('sid1');
        $deleteProp = new ReflectionProperty(PdoSessionPersistence::class, 'deleteStmt');
        $first = $deleteProp->getValue($this->persistence);
        $this->assertInstanceOf(PDOStatement::class, $first);

        $this->persistence->delete('sid2');
        $this->assertSame($first, $deleteProp->getValue($this->persistence));
    }

    public function testCustomTableNameIsHonored(): void
    {
        $this->pdo->exec('CREATE TABLE custom_sessions (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time TIMESTAMP NOT NULL)');
        $persistence = new PdoSessionPersistence($this->pdo, ['table' => 'custom_sessions']);

        $persistence->save('sid1', ['x' => 1]);

        $this->assertSame(['x' => 1], $persistence->load('sid1'));
        $this->assertNull($this->persistence->load('sid1'), 'the default-table persistence must not see the custom table\'s row');
    }
}
