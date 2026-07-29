<?php

use PHPUnit\Framework\TestCase;
use Quiote\Session\Pdo\PdoSessionPersistence;

final class PdoSessionPersistenceTest extends TestCase
{
    private PDO $pdo;
    private PdoSessionPersistence $persistence;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time INTEGER NOT NULL)');
        $this->persistence = new PdoSessionPersistence($this->pdo);
    }

    public function testLoadUnknownSessionReturnsNull(): void
    {
        $this->assertNull($this->persistence->load('missing'));
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        $this->persistence->save('sid-1', ['user_id' => 42, 'flash' => ['ok']]);

        $this->assertSame(['user_id' => 42, 'flash' => ['ok']], $this->persistence->load('sid-1'));
    }

    public function testSaveTwiceUpdatesInPlace(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->save('sid-1', ['a' => 2]);

        $this->assertSame(['a' => 2], $this->persistence->load('sid-1'));
        $this->assertSame(1, self::column($this->pdo, 'SELECT COUNT(*) FROM session'));
    }

    public function testDeleteRemovesRow(): void
    {
        $this->persistence->save('sid-1', ['a' => 1]);
        $this->persistence->delete('sid-1');

        $this->assertNull($this->persistence->load('sid-1'));
    }

    public function testDeleteOfMissingRowIsBestEffort(): void
    {
        $this->persistence->delete('never-existed');
        $this->addToAssertionCount(1);
    }
    /** PDO::query() is typed PDOStatement|false; narrow it once here. */
    private static function column(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

}
