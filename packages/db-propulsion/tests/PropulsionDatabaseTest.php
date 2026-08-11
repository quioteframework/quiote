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
        (new ReflectionProperty(PropulsionDatabase::class, 'appliedConfiguration'))->setValue(null, null);
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

    /**
     * reset() is Database's teardown to the pre-initialize() state, so it has
     * to leave nothing behind that a later use could run against -- the
     * parameters included.
     */
    public function testResetReturnsTheDatabaseToItsPreInitializeState(): void
    {
        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        (new ReflectionProperty($manager, 'databases'))->setValue($manager, ['propulsion' => $db]);
        $db->initialize($manager, [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
        ]);
        $db->getPropulsionConnection();

        $db->reset();

        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db));
        $this->assertFalse($db->hasParameter('config'));
    }

    public function testGetConfigPathReturnsTheConfiguredParameter(): void
    {
        $runtimeConfig = $this->writeRuntimeConfigFile();

        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), ['config' => $runtimeConfig, 'datasource' => 'runtime']);

        $this->assertSame($runtimeConfig, $db->getConfigPath());
    }

    public function testInitializeRequiresAConfigParameter(): void
    {
        $db = new PropulsionDatabase();

        $this->expectException(\Quiote\Exception\DatabaseException::class);
        $this->expectExceptionMessage('requires a non-empty string "config" parameter');

        $db->initialize(new DatabaseManager(), []);
    }

    public function testInitializeRejectsAnEmptyConfigParameter(): void
    {
        $db = new PropulsionDatabase();

        $this->expectException(\Quiote\Exception\DatabaseException::class);
        $this->expectExceptionMessage('requires a non-empty string "config" parameter');

        $db->initialize(new DatabaseManager(), ['config' => '']);
    }

    /**
     * The path is reported back as configured rather than as expanded, so a
     * typo in `%core.config_dir%/...` is recognisable in the message.
     */
    public function testInitializeRejectsAConfigPathThatIsNotAFile(): void
    {
        $db = new PropulsionDatabase();
        $missing = $this->newTempFilePath('.php');

        $this->expectException(\Quiote\Exception\DatabaseException::class);
        $this->expectExceptionMessage('requires a readable "config" file path');

        $db->initialize(new DatabaseManager(), ['config' => $missing]);
    }

    public function testInitializeRejectsAConfigFileThatDoesNotReturnAnArray(): void
    {
        $path = $this->newTempFilePath('.php');
        file_put_contents($path, "<?php\nreturn 'not an array';\n");

        $db = new PropulsionDatabase();

        $this->expectException(\Quiote\Exception\DatabaseException::class);
        $this->expectExceptionMessage('to return an array, got string');

        $db->initialize(new DatabaseManager(), ['config' => $path]);
    }

    public function testTheDatasourceFallsBackToTheConfigFileDefault(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), ['config' => $this->writeRuntimeConfigFile()]);

        $this->assertSame('runtime', $db->getDatasource());
    }

    /**
     * `default` names no datasource of its own -- it is the marker for "use
     * whatever the config file declares as default".
     */
    public function testAnExplicitDefaultDatasourceStillResolvesFromTheConfigFile(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'default',
        ]);

        $this->assertSame('runtime', $db->getDatasource());
    }

    /** Propel-shaped config files nest everything under a `propel` key. */
    public function testTheDatasourceIsAlsoReadFromAPropelNestedConfig(): void
    {
        $path = $this->writeConfigFile(['propel' => $this->runtimeDatasources()]);

        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), ['config' => $path]);

        $this->assertSame('runtime', $db->getDatasource());
    }

    public function testAConfigWithNoDefaultDatasourceIsReported(): void
    {
        $datasources = $this->runtimeDatasources();
        unset($datasources['datasources']['default']);
        $path = $this->writeConfigFile($datasources);

        $db = new PropulsionDatabase();

        $this->expectException(\Quiote\Exception\DatabaseException::class);
        $this->expectExceptionMessage('has no datasource');

        $db->initialize(new DatabaseManager(), ['config' => $path]);
    }

    public function testOverridesAreAppliedToTheConfiguration(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'overrides' => ['datasources.runtime.adapter' => 'sqlite'],
        ]);

        $config = Propulsion::getConfiguration(\Propulsion\Config\PropulsionConfiguration::TYPE_OBJECT);
        $this->assertInstanceOf(\Propulsion\Config\PropulsionConfiguration::class, $config);
        $this->assertSame('sqlite', $config->getParameter('datasources.runtime.adapter'));
    }

    public function testInitQueriesAreAppendedToTheDatasourceConnectionQueries(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON', 'PRAGMA journal_mode = WAL'],
        ]);

        $flattened = Propulsion::getConfiguration(\Propulsion\Config\PropulsionConfiguration::TYPE_ARRAY_FLAT);
        if (!is_array($flattened)) {
            self::fail('the flattened configuration should be an array, got ' . get_debug_type($flattened));
        }

        $key = 'datasources.runtime.connection.settings.queries.query';
        $this->assertSame('PRAGMA foreign_keys = ON', $flattened[$key . '.0'] ?? null);
        $this->assertSame('PRAGMA journal_mode = WAL', $flattened[$key . '.1'] ?? null);
    }

    /**
     * `init_queries` adds to what the runtime config already declares for the
     * datasource. Reading those back through the configuration's own
     * getParameter() cannot see a list at all -- it resolves against the
     * flattened map, where the list exists only as `<path>.0`, `<path>.1` --
     * so a naive read silently drops every query the config file set.
     */
    public function testInitQueriesAreAppendedToQueriesTheConfigFileAlreadyDeclares(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeConfigFile($this->runtimeDatasources(['PRAGMA busy_timeout = 5000'])),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ]);

        $this->assertSame(
            ['PRAGMA busy_timeout = 5000', 'PRAGMA foreign_keys = ON'],
            $this->configuredQueries('runtime'),
        );
    }

    /** A datasource may declare its single query as a bare string rather than a list. */
    public function testASingleConfiguredQueryDeclaredAsAStringIsKept(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeConfigFile($this->runtimeDatasources('PRAGMA busy_timeout = 5000')),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ]);

        $this->assertSame(
            ['PRAGMA busy_timeout = 5000', 'PRAGMA foreign_keys = ON'],
            $this->configuredQueries('runtime'),
        );
    }

    public function testConfiguredQueriesSurviveWithNoInitQueriesOfOurOwn(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeConfigFile($this->runtimeDatasources(['PRAGMA busy_timeout = 5000'])),
            'datasource' => 'runtime',
        ]);

        $this->assertSame(['PRAGMA busy_timeout = 5000'], $this->configuredQueries('runtime'));
    }

    /**
     * Queries belonging to another datasource are none of this database's
     * business, so they must be neither read nor overwritten.
     */
    public function testOnlyTheResolvedDatasourceQueriesAreTouched(): void
    {
        $config = [
            'datasources' => [
                'default' => 'runtime',
                'runtime' => $this->datasource(),
                'other' => $this->datasource(['PRAGMA cache_size = 1']),
            ],
        ];

        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeConfigFile($config),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ]);

        $this->assertSame(['PRAGMA foreign_keys = ON'], $this->configuredQueries('runtime'));
        $this->assertSame(['PRAGMA cache_size = 1'], $this->configuredQueries('other'));
    }

    /**
     * Instance pooling is a process-wide switch, so the parameter is the only
     * way an application declares it per database -- and omitting it must
     * leave whatever the process already had alone.
     */
    public function testInstancePoolingIsSwitchedByTheParameter(): void
    {
        $db = new PropulsionDatabase();

        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'enable_instance_pooling' => false,
        ]);
        $this->assertFalse(Propulsion::isInstancePoolingEnabled());

        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
        ]);
        $this->assertFalse(Propulsion::isInstancePoolingEnabled(), 'an absent parameter changes nothing');

        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'enable_instance_pooling' => true,
        ]);
        $this->assertTrue(Propulsion::isInstancePoolingEnabled());
    }

    public function testPingIsTrueBeforeAnythingHasConnected(): void
    {
        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
        ]);

        $this->assertTrue($db->ping(), 'lazy connect handles the first use');
    }

    /**
     * A connection that is not a PDO cannot be probed, so ping has to report
     * the connection as unusable and clear it rather than claim health.
     */
    public function testPingIsFalseAndClearsAConnectionThatIsNotAPdo(): void
    {
        $db = $this->databaseWithFakeConnection($this->createStub(\Propulsion\Connection\PropulsionPDO::class));
        $db->getConnection();

        $this->assertFalse($db->ping());
        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db));
    }

    public function testGetPropulsionConnectionRejectsAConnectionOfTheWrongType(): void
    {
        $db = $this->databaseWithFakeConnection(new \stdClass());

        $this->expectException(\Quiote\Exception\DatabaseException::class);
        $this->expectExceptionMessage('expected a ' . \Propulsion\Connection\PropulsionPDO::class . ' connection, got stdClass');

        $db->getPropulsionConnection();
    }

    public function testShutdownDropsTheConnectionSoTheNextUseReconnects(): void
    {
        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        (new ReflectionProperty($manager, 'databases'))->setValue($manager, ['propulsion' => $db]);
        $db->initialize($manager, [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
        ]);

        $first = $db->getPropulsionConnection();
        $db->shutdown();

        $this->assertNull((new ReflectionProperty(\Quiote\Database\Database::class, 'connection'))->getValue($db));
        $this->assertNotSame($first, $db->getPropulsionConnection());
    }

    /**
     * Re-initializing with the configuration Propulsion already carries must not touch its
     * connection map.
     *
     * Propulsion::initialize() is nothing but a reset of that map, and the reset does not close
     * anything: PHP releases a PDO when its last reference goes, and this adapter holds one in
     * $this->connection. So clearing the map leaves the old connection open -- with whatever
     * transaction and table locks it holds -- while the next getConnection() opens a second
     * backend beside it. One per initialize(), for the life of the process.
     */
    public function testReInitializingWithTheSameConfigurationKeepsTheLiveConnection(): void
    {
        $runtimeConfig = $this->writeRuntimeConfigFile();
        $parameters = ['config' => $runtimeConfig, 'datasource' => 'runtime'];

        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        (new ReflectionProperty($manager, 'databases'))->setValue($manager, ['propulsion' => $db]);
        $db->initialize($manager, $parameters);

        $connection = Propulsion::getConnection('runtime');

        $second = new PropulsionDatabase();
        $second->initialize($manager, $parameters);

        $this->assertSame(
            $connection,
            Propulsion::getConnection('runtime'),
            'the connection map was cleared, so a second backend would be opened',
        );
    }

    /** An identical re-initialize must also not re-apply init_queries onto themselves. */
    public function testReInitializingDoesNotAccumulateInitQueries(): void
    {
        $parameters = [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ];

        $db = new PropulsionDatabase();
        $db->initialize(new DatabaseManager(), $parameters);
        $db->initialize(new DatabaseManager(), $parameters);

        $this->assertSame(['PRAGMA foreign_keys = ON'], $this->configuredQueries('runtime'));
    }

    /**
     * A configuration that genuinely differs still reconfigures, dropping the map on purpose:
     * the connections it holds were opened against parameters that no longer apply.
     */
    public function testAChangedConfigurationStillReconfigures(): void
    {
        $manager = new DatabaseManager();

        $first = new PropulsionDatabase();
        $first->initialize($manager, ['config' => $this->writeRuntimeConfigFile(), 'datasource' => 'runtime']);
        $connection = Propulsion::getConnection('runtime');

        $second = new PropulsionDatabase();
        $second->initialize($manager, [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ]);

        $this->assertNotSame($connection, Propulsion::getConnection('runtime'));
    }

    /**
     * A reconfiguration on another PropulsionDatabase instance calls
     * Propulsion::initialize(), which drops the connection map without
     * notifying this instance. If getConnection() trusted its own cache
     * here, it would keep handing out the pre-reset connection while every
     * other consumer of Propulsion resolves the fresh one -- two different
     * backends behind what looks like a single database.
     */
    public function testGetConnectionResolvesAgainAfterAnotherInstanceReconfiguresPropulsion(): void
    {
        $manager = new DatabaseManager();

        $first = new PropulsionDatabase();
        $first->initialize($manager, ['config' => $this->writeRuntimeConfigFile(), 'datasource' => 'runtime']);
        $stale = $first->getConnection();

        $second = new PropulsionDatabase();
        $second->initialize($manager, [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ]);

        $this->assertNotSame($stale, $first->getConnection());
        $this->assertSame(Propulsion::getConnection('runtime'), $first->getConnection());
    }

    /**
     * getResource() is the other public entry point onto the cached
     * connection field, so it needs the same re-resolution guarantee as
     * getConnection() -- otherwise a caller reaching through it would still
     * see the stale backend.
     */
    public function testGetResourceResolvesAgainAfterAnotherInstanceReconfiguresPropulsion(): void
    {
        $manager = new DatabaseManager();

        $first = new PropulsionDatabase();
        $first->initialize($manager, ['config' => $this->writeRuntimeConfigFile(), 'datasource' => 'runtime']);
        $stale = $first->getResource();

        $second = new PropulsionDatabase();
        $second->initialize($manager, [
            'config' => $this->writeRuntimeConfigFile(),
            'datasource' => 'runtime',
            'init_queries' => ['PRAGMA foreign_keys = ON'],
        ]);

        $this->assertNotSame($stale, $first->getResource());
        $this->assertSame(Propulsion::getConnection('runtime'), $first->getResource());
    }

    public function testPluginRegistersPropulsionAlias(): void
    {
        $plugin = new PropulsionPlugin();
        $plugin->register(new PluginRegistrar('quiote/propulsion'));

        $this->assertSame(PropulsionDatabase::class, DatabaseDriverRegistry::resolve('propulsion'));
    }

    /**
     * A PropulsionDatabase whose connect() hands back $connection instead of
     * opening a datasource, for the paths that only trigger when the
     * datasource returns something unexpected.
     */
    private function databaseWithFakeConnection(mixed $connection): PropulsionDatabase
    {
        return new class ($connection) extends PropulsionDatabase {
            public function __construct(private readonly mixed $fake)
            {
                parent::__construct();
            }

            #[\Override]
            protected function connect()
            {
                $this->connection = $this->resource = $this->fake;
            }
        };
    }

    /**
     * The connection queries Propulsion holds for a datasource, read from the
     * nested parameters -- the flattened map only ever exposes the individual
     * `<path>.0`, `<path>.1` entries.
     *
     * @return list<mixed>
     */
    private function configuredQueries(string $datasource): array
    {
        $parameters = Propulsion::getConfiguration(\Propulsion\Config\PropulsionConfiguration::TYPE_ARRAY);
        $path = ['datasources', $datasource, 'connection', 'settings', 'queries', 'query'];

        $node = $parameters;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                self::fail(sprintf('no queries configured at "%s" for datasource "%s"', $segment, $datasource));
            }
            $node = $node[$segment];
        }

        return is_array($node) ? array_values($node) : [$node];
    }

    /**
     * A runtime config declaring a single `runtime` datasource, optionally
     * carrying connection queries of its own.
     *
     * @param mixed $queries Value for `connection.settings.queries.query`; omitted when null.
     * @return array{datasources: array<string, mixed>}
     */
    private function runtimeDatasources(mixed $queries = null): array
    {
        return ['datasources' => ['default' => 'runtime', 'runtime' => $this->datasource($queries)]];
    }

    /**
     * @param mixed $queries Value for `connection.settings.queries.query`; omitted when null.
     * @return array<string, mixed>
     */
    private function datasource(mixed $queries = null): array
    {
        $connection = ['dsn' => 'sqlite:' . $this->newTempFilePath('.sqlite')];
        if ($queries !== null) {
            $connection['settings'] = ['queries' => ['query' => $queries]];
        }

        return ['adapter' => 'sqlite', 'connection' => $connection];
    }

    /** @param array<string, mixed> $config */
    private function writeConfigFile(array $config): string
    {
        $configPath = $this->newTempFilePath('.php');
        file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

        return $configPath;
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
