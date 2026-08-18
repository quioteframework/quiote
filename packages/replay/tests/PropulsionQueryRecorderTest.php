<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Propulsion\Propulsion;
use Quiote\Database\Adapter\Propulsion\PropulsionDatabase;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Database\DatabaseManager;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Db\PropulsionQueryRecorder;
use Quiote\Replay\Replay\EffectLedger;

/**
 * PropulsionQueryRecorder against a real Propulsion/SQLite connection --
 * per the class's own docblock, Propulsion's observer seam needs no
 * decoration, so these tests register the recorder with
 * Propulsion::addQueryObserver() and run real queries through it.
 */
final class PropulsionQueryRecorderTest extends TestCase
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
        Propulsion::clearQueryObservers();
        (new ReflectionProperty(PropulsionDatabase::class, 'appliedConfiguration'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        if (class_exists(Propulsion::class)) {
            Propulsion::clearQueryObservers();
            Propulsion::close();
        }
        DatabaseDriverRegistry::reset();

        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function connect(): \Propulsion\Connection\PropulsionPDO
    {
        $runtimeConfig = $this->writeRuntimeConfigFile();

        $db = new PropulsionDatabase();
        $manager = new DatabaseManager();
        $ref = new ReflectionProperty($manager, 'databases');
        $ref->setValue($manager, ['propulsion' => $db]);

        $db->initialize($manager, [
            'config' => $runtimeConfig,
            'datasource' => 'runtime',
        ]);

        return $db->getPropulsionConnection();
    }

    public function testASuccessfulQueryProducesOneDbEffectWithTheRightFingerprint(): void
    {
        $ledger = new EffectLedger();
        Propulsion::addQueryObserver(new PropulsionQueryRecorder($ledger));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $effects = array_values(array_filter(
            $ledger->all(),
            static fn($e) => str_contains($e->fingerprint, 'CREATE TABLE'),
        ));
        $this->assertCount(1, $effects);
        $this->assertSame(EffectKind::Db, $effects[0]->kind);
        $this->assertSame('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)', $effects[0]->fingerprint);
        $this->assertSame('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)', $effects[0]->call['sql']);
        $this->assertSame(\Propulsion\Observability\QueryExecution::SOURCE_EXEC, $effects[0]->call['source']);
    }

    public function testTwoSequentialQueriesProduceTwoEffectsInOrder(): void
    {
        $ledger = new EffectLedger();
        Propulsion::addQueryObserver(new PropulsionQueryRecorder($ledger));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $conn->exec("INSERT INTO items (name) VALUES ('quiote')");

        $all = $ledger->all();
        $this->assertGreaterThanOrEqual(2, count($all));
        $createIndex = null;
        $insertIndex = null;
        foreach ($all as $i => $effect) {
            if (str_contains($effect->fingerprint, 'CREATE TABLE')) {
                $createIndex = $i;
            }
            if (str_contains($effect->fingerprint, 'INSERT INTO')) {
                $insertIndex = $i;
            }
        }
        $this->assertNotNull($createIndex);
        $this->assertNotNull($insertIndex);
        $this->assertLessThan($insertIndex, $createIndex, 'CREATE must be recorded before INSERT');
    }

    /**
     * exec()'s own return value (rows affected) is what QueryExecution
     * reports for a non-SELECT statement -- verified against the real value
     * Propulsion hands back, not an assumed one.
     */
    public function testExecEffectResultCarriesTheRealAffectedRowCount(): void
    {
        $ledger = new EffectLedger();
        Propulsion::addQueryObserver(new PropulsionQueryRecorder($ledger));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $affected = $conn->exec("INSERT INTO items (name) VALUES ('a'), ('b')");

        $insertEffect = null;
        foreach ($ledger->all() as $effect) {
            if (str_contains($effect->fingerprint, 'INSERT INTO')) {
                $insertEffect = $effect;
            }
        }
        $this->assertNotNull($insertEffect);
        $this->assertSame($affected, $insertEffect->result);
    }

    /**
     * A SELECT's row count is documented (both by PDOStatement and by
     * QueryExecution) as unreliable, so Propulsion reports null for it --
     * this recorder must carry that through rather than inventing a number.
     */
    public function testSelectEffectResultIsNullRowCountNotFabricated(): void
    {
        $ledger = new EffectLedger();
        Propulsion::addQueryObserver(new PropulsionQueryRecorder($ledger));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $conn->exec("INSERT INTO items (name) VALUES ('quiote')");
        $stmt = $conn->query('SELECT name FROM items');
        $this->assertNotFalse($stmt);
        $stmt->fetchAll();

        $selectEffect = null;
        foreach ($ledger->all() as $effect) {
            if (str_contains($effect->fingerprint, 'SELECT name FROM items')) {
                $selectEffect = $effect;
            }
        }
        $this->assertNotNull($selectEffect);
        $this->assertNull($selectEffect->result);
    }

    public function testAFailingQueryDoesNotProduceALedgerEntry(): void
    {
        $ledger = new EffectLedger();
        Propulsion::addQueryObserver(new PropulsionQueryRecorder($ledger));

        $conn = $this->connect();

        try {
            $conn->exec('SELECT * FROM a_table_that_does_not_exist');
            $this->fail('expected the bad statement to throw');
        } catch (\PDOException) {
            // expected
        }

        $this->assertSame([], $ledger->all(), 'a failed statement must not be recorded');
    }

    public function testEveryRecordedEffectHasADurationDerivedFromTheExecutionItself(): void
    {
        $ledger = new EffectLedger();
        Propulsion::addQueryObserver(new PropulsionQueryRecorder($ledger));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $effect = $ledger->all()[0];
        $this->assertNotNull($effect->durationMicros);
        $this->assertGreaterThanOrEqual(0, $effect->durationMicros);
    }

    /**
     * The recorder must be transparent: the calling code sees the exact same
     * return values whether or not the recorder is registered.
     */
    public function testDoesNotAlterTheRealQueryBehaviorOrReturnValues(): void
    {
        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $unobservedAffected = $conn->exec("INSERT INTO items (name) VALUES ('a')");
        $unobservedStmt = $conn->query('SELECT name FROM items');
        $this->assertNotFalse($unobservedStmt);
        $unobservedValue = $unobservedStmt->fetchColumn();

        Propulsion::close();
        DatabaseDriverRegistry::reset();
        (new ReflectionProperty(PropulsionDatabase::class, 'appliedConfiguration'))->setValue(null, null);

        Propulsion::addQueryObserver(new PropulsionQueryRecorder(new EffectLedger()));
        $observedConn = $this->connect();
        $observedConn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $observedAffected = $observedConn->exec("INSERT INTO items (name) VALUES ('a')");
        $observedStmt = $observedConn->query('SELECT name FROM items');
        $this->assertNotFalse($observedStmt);
        $observedValue = $observedStmt->fetchColumn();

        $this->assertSame($unobservedAffected, $observedAffected);
        $this->assertSame($unobservedValue, $observedValue);
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
                    ],
                ],
            ],
        ];

        file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

        return $configPath;
    }

    private function newTempFilePath(string $suffix): string
    {
        $path = sprintf('%s/quiote-replay-propulsion-%s%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $suffix);
        $this->filesToDelete[] = $path;

        return $path;
    }
}
