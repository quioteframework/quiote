<?php

use Quiote\Context;
use Quiote\Session\FileSessionFactory;
use Quiote\Session\PdoSessionFactory;
use Quiote\Session\PdoSessionPersistence;
use Quiote\Session\FileSessionPersistence;
use Quiote\Testing\UnitTestCase;

/**
 * The `session` slot factories: the seam between untyped factories config and
 * a constructed SessionPersistenceInterface.
 */
class SessionFactoryTest extends UnitTestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->dirs = [];

        parent::tearDown();
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/quiote-session-factory-' . bin2hex(random_bytes(6));
        $this->dirs[] = $dir;

        return $dir;
    }

    public function testFileFactoryBuildsAWorkingBackend(): void
    {
        $persistence = (new FileSessionFactory())->createPersistence(
            $this->getContext(),
            ['dir' => $this->tempDir()],
        );

        $this->assertInstanceOf(FileSessionPersistence::class, $persistence);

        $persistence->save('sid-1', ['user_id' => 42]);
        $this->assertSame(['user_id' => 42], $persistence->load('sid-1'));
    }

    /**
     * An unconfigured or non-string `dir` must not silently become something
     * unwritable; it falls back to a location under the app directory.
     */
    public function testFileFactoryFallsBackToAnAppRelativeDirectory(): void
    {
        $persistence = (new FileSessionFactory())->createPersistence($this->getContext(), []);

        $this->assertInstanceOf(FileSessionPersistence::class, $persistence);
    }

    public function testPdoFactoryBuildsAWorkingBackendFromTheContextConnection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE session (sess_id VARCHAR(64) PRIMARY KEY, sess_data TEXT NOT NULL, sess_time TIMESTAMP NOT NULL)');

        // The factory resolves the database manager from the container, so that is what a stub binds.
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
        $context->getContainer()->set(
            \Quiote\Database\DatabaseManager::class,
            new SessionFactoryTestDatabaseManager($pdo),
        );

        $persistence = (new PdoSessionFactory())->createPersistence($context, []);

        $this->assertInstanceOf(PdoSessionPersistence::class, $persistence);
        $persistence->save('sid-1', ['user_id' => 7]);
        $this->assertSame(['user_id' => 7], $persistence->load('sid-1'));
    }

    /**
     * Failure path: a misconfigured or disabled database must say so, rather
     * than failing later with a confusing type error deep in the backend.
     */
    public function testPdoFactoryExplainsItselfWhenThereIsNoConnection(): void
    {
        // Nothing bound: the shape a context with core.use_database off actually has.
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

        $this->expectException(\Quiote\Exception\StorageException::class);
        $this->expectExceptionMessage('needs a PDO connection');

        (new PdoSessionFactory())->createPersistence($context, ['database' => 'sessions']);
    }
}

/**
 * Enough of a database manager to hand the session factory one connection. Extends the real class so
 * the container's own type check passes, and overrides only the reach the factory makes.
 */
final class SessionFactoryTestDatabaseManager extends \Quiote\Database\DatabaseManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    #[\Override]
    public function getDatabase($name = null): \Quiote\Database\Database
    {
        return new SessionFactoryTestDatabase($this->pdo);
    }
}

final class SessionFactoryTestDatabase extends \Quiote\Database\Database
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
