<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Session\Pdo\PdoSessionFactory;
use Quiote\Session\Pdo\PdoSessionPersistence;

/**
 * The `session` slot factory: an application must be able to name this class in
 * factories config and get a working backend, with no hand-written wrapper.
 */
final class PdoSessionFactoryTest extends TestCase
{
    private function contextWith(?PDO $pdo): Context
    {
        return new class ($pdo) extends Context {
            public function __construct(private ?PDO $pdo)
            {
            }

            #[\Override]
            public function getDatabaseConnection($name = null): ?PDO
            {
                return $this->pdo;
            }
        };
    }

    public function testItBuildsAWorkingBackendFromTheContextConnection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time TIMESTAMP NOT NULL)');

        $persistence = (new PdoSessionFactory())->createPersistence($this->contextWith($pdo), []);

        $this->assertInstanceOf(PdoSessionPersistence::class, $persistence);
        $persistence->save('sid-1', ['user_id' => 7]);
        $this->assertSame(['user_id' => 7], $persistence->load('sid-1'));
    }

    /** Failure path: a disabled or misconfigured database must say so. */
    public function testItExplainsItselfWithoutAConnection(): void
    {
        $this->expectException(\Quiote\Exception\StorageException::class);
        $this->expectExceptionMessage('needs a PDO connection');

        (new PdoSessionFactory())->createPersistence($this->contextWith(null), ['database' => 'sessions']);
    }
}
