<?php

require_once dirname(__DIR__) . '/src/PropulsionDatabase.php';
require_once dirname(__DIR__) . '/src/PropulsionPlugin.php';

use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Propulsion\PropulsionDatabase;
use Quiote\Database\Adapter\Propulsion\PropulsionPlugin;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Database\DatabaseManager;
use Quiote\Plugin\PluginRegistrar;
use Propulsion\Propulsion;

class PropulsionDatabaseTest extends TestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    protected function setUp(): void
    {
        if (!class_exists(Propulsion::class)) {
            $this->markTestSkipped('quioteframework/propulsion not installed');
        }
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite driver not available');
        }

        DatabaseDriverRegistry::reset();
        Propulsion::close();
    }

    protected function tearDown(): void
    {
        if (class_exists(Propulsion::class)) {
            Propulsion::close();
        }
        DatabaseDriverRegistry::reset();

        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function testSqliteRoundTripAndTypedAccessor(): void
    {
        $runtimeConfig = $this->writeRuntimeConfigFile();

        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        $ref = new ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, ['propulsion' => $db]);

        $db->initialize($manager, [
            'config' => $runtimeConfig,
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ]);

        $conn = $db->getPropulsionConnection();
        $conn->exec('CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $conn->exec("INSERT INTO items (name) VALUES ('quiote')");
        $stmt = $conn->query('SELECT name FROM items WHERE id = 1');
        $this->assertNotFalse($stmt);
        $value = $stmt->fetchColumn();

        $this->assertSame('quiote', $value);
        $this->assertSame('runtime', $db->getDatasource());
        $this->assertTrue($db->ping());
        $this->assertSame($conn, $db->getPdo());
    }

    /**
     * PropulsionPDO is an interface, and only its concrete driver-specific
     * implementations extend PDO, so a connection that satisfies the interface
     * without being a PDO has to be reported rather than handed to a caller
     * that asked for a raw PDO handle.
     */
    public function testGetPdoRejectsAPropulsionConnectionThatIsNotAPdo(): void
    {
        $fake = $this->createStub(\Propulsion\Connection\PropulsionPDO::class);

        $db = new class ($fake) extends PropulsionDatabase {
            public function __construct(private readonly \Propulsion\Connection\PropulsionPDO $fake)
            {
                parent::__construct();
            }

            #[\Override]
            protected function connect()
            {
                $this->connection = $this->resource = $this->fake;
            }
        };

        $this->expectException(\Quiote\Exception\DatabaseException::class);
        $this->expectExceptionMessage('does not extend PDO');
        $db->getPdo();
    }

    public function testResetClearsRequestScopedSessionState(): void
    {
        $runtimeConfig = $this->writeRuntimeConfigFile();

        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        $ref = new ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, ['propulsion' => $db]);
        $db->initialize($manager, ['config' => $runtimeConfig, 'datasource' => 'runtime']);

        // stdClass rather than an invented 'TestPeer': the pool keys on the class name only, and a
        // real class-string is what Propulsion's signature asks for.
        Propulsion::getSession()->addPooledInstance(\stdClass::class, '1', (object) ['id' => 1]);
        $this->assertNotNull(Propulsion::getSession()->getPooledInstance(\stdClass::class, '1'));

        $db->reset();

        $this->assertNull(Propulsion::getSession()->getPooledInstance(\stdClass::class, '1'));
    }

    public function testPluginRegistersPropulsionAlias(): void
    {
        $plugin = new PropulsionPlugin();
        $plugin->register(new PluginRegistrar('quiote/propulsion'));

        $this->assertSame(PropulsionDatabase::class, DatabaseDriverRegistry::resolve('propulsion'));
    }

    private function writeRuntimeConfigFile(): string
    {
        $sqlitePath = $this->newTempFilePath('.sqlite');
        $configPath = $this->newTempFilePath('.php');

        $config = [
            'datasources' => [
                'default' => 'runtime',
                'runtime' => [
                    'adapter' => 'sqlite',
                    'connection' => [
                        // No 'classname': the adapter's getDefaultPdoClass() picks the driver's
                        // connection class. PropulsionPDO itself is an interface, so naming it here
                        // fails class_exists(), and naming a concrete driver class would couple this
                        // test to Propulsion's internal class layout for no gain.
                        'dsn' => 'sqlite:' . $sqlitePath,
                    ],
                ],
            ],
        ];

        file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

        return $configPath;
    }

    private function newTempFilePath(string $suffix): string
    {
        $path = sprintf('%s/quiote-db-propulsion-%s%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $suffix);
        $this->filesToDelete[] = $path;

        return $path;
    }
}
