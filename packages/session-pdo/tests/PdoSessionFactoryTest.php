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
    /**
     * The factory resolves the database manager from the container, so a stub binds one there. Passing
     * null binds nothing, which is the shape a context with `core.use_database` off actually has.
     */
    private function contextWith(?PDO $pdo): Context
    {
        $context = new class ('session-factory-test') extends Context {
            /**
             * Public only so the test can construct one; the parent's is protected. Nothing else is
             * initialized, because the factory needs a container and nothing more.
             */
            public function __construct(string $name)
            {
                parent::__construct($name);
            }
        };

        if ($pdo instanceof PDO) {
            $context->getContainer()->set(
                \Quiote\Database\DatabaseManager::class,
                new PdoSessionFactoryTestDatabaseManager($pdo),
            );
        }

        return $context;
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

/**
 * Enough of a database manager to hand the factory one connection. Extends the real classes so the
 * container's type check passes, overriding only the reach the factory makes.
 */
final class PdoSessionFactoryTestDatabaseManager extends \Quiote\Database\DatabaseManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    #[\Override]
    public function getDatabase($name = null): \Quiote\Database\Database
    {
        return new PdoSessionFactoryTestDatabase($this->pdo);
    }
}

final class PdoSessionFactoryTestDatabase extends \Quiote\Database\Database
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    #[\Override]
    public function getConnection()
    {
        return $this->pdo;
    }

    #[\Override]
    public function connect()
    {
        // Already connected: the PDO handle is supplied ready-made.
    }

    #[\Override]
    public function shutdown()
    {
    }
}
