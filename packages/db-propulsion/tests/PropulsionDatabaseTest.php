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
        $value = $conn->query('SELECT name FROM items WHERE id = 1')->fetchColumn();

        $this->assertSame('quiote', $value);
        $this->assertSame('runtime', $db->getDatasource());
        $this->assertTrue($db->ping());
        $this->assertSame($conn, $db->getPdo());
    }

    public function testResetClearsRequestScopedSessionState(): void
    {
        $runtimeConfig = $this->writeRuntimeConfigFile();

        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        $ref = new ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, ['propulsion' => $db]);
        $db->initialize($manager, ['config' => $runtimeConfig, 'datasource' => 'runtime']);

        Propulsion::getSession()->addPooledInstance('TestPeer', '1', (object) ['id' => 1]);
        $this->assertNotNull(Propulsion::getSession()->getPooledInstance('TestPeer', '1'));

        $db->reset();

        $this->assertNull(Propulsion::getSession()->getPooledInstance('TestPeer', '1'));
    }

    public function testPluginRegistersPropulsionAlias(): void
    {
        $plugin = new PropulsionPlugin();
        $plugin->register(new PluginRegistrar($plugin->name()));

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
                        'dsn' => 'sqlite:' . $sqlitePath,
                        'classname' => 'PropulsionPDO',
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
