<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Propulsion\Propulsion;
use Quiote\Database\Adapter\Propulsion\PropulsionDatabase;
use Quiote\Database\DatabaseDriverRegistry;
use Quiote\Database\DatabaseManager;
use Quiote\Replay\Adapter\Propulsion\PropulsionQueryRecorder;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Recording\EffectLedgerRegistry;
use Quiote\Replay\Recording\Redactor;
use Quiote\Replay\Replay\EffectLedger;

/**
 * PropulsionQueryRecorder against a real Propulsion/SQLite connection --
 * per the class's own docblock, it's registered exactly once (mirroring how
 * ReplayPropulsionPlugin registers it at boot) and routes by correlation id,
 * so every test here sets one via Propulsion::setCorrelationId() and
 * registers the ledger it expects effects to land in via EffectLedgerRegistry.
 */
final class PropulsionQueryRecorderTest extends TestCase
{
    private const CORRELATION_ID = 'test-correlation-id';

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
        EffectLedgerRegistry::reset();
    }

    protected function tearDown(): void
    {
        if (class_exists(Propulsion::class)) {
            Propulsion::clearQueryObservers();
            Propulsion::close();
            Propulsion::setCorrelationId(null);
        }
        DatabaseDriverRegistry::reset();
        EffectLedgerRegistry::reset();

        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** Registers the recorder and a ledger under the test's fixed correlation id. */
    private function recordingLedger(?Redactor $redactor = null): EffectLedger
    {
        $ledger = new EffectLedger();
        Propulsion::addQueryObserver(new PropulsionQueryRecorder(static fn(): Redactor => $redactor ?? new Redactor([], [], [])));
        Propulsion::setCorrelationId(self::CORRELATION_ID);
        EffectLedgerRegistry::register(self::CORRELATION_ID, $ledger);

        return $ledger;
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

    private function findEffect(EffectLedger $ledger, string $needle): ?\Quiote\Replay\Cassette\Effect
    {
        foreach ($ledger->all() as $effect) {
            if (str_contains($effect->fingerprint, $needle)) {
                return $effect;
            }
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    private function boundParam(\Quiote\Replay\Cassette\Effect $effect, string $placeholder): array
    {
        $boundParams = $effect->call['bound_params'];
        $this->assertIsArray($boundParams);
        $param = $boundParams[$placeholder];
        $this->assertIsArray($param);

        return $param;
    }

    public function testASuccessfulQueryProducesOneDbEffectWithTheRightFingerprint(): void
    {
        $ledger = $this->recordingLedger();

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $effect = $this->findEffect($ledger, 'CREATE TABLE');
        $this->assertNotNull($effect);
        $this->assertSame(EffectKind::Db, $effect->kind);
        $this->assertSame('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)', $effect->fingerprint);
        $this->assertSame('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)', $effect->call['sql']);
        $this->assertSame(\Propulsion\Observability\QueryExecution::SOURCE_EXEC, $effect->call['source']);
    }

    public function testTwoSequentialQueriesProduceTwoEffectsInOrder(): void
    {
        $ledger = $this->recordingLedger();

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $conn->exec("INSERT INTO items (name) VALUES ('quiote')");

        $createEffect = $this->findEffect($ledger, 'CREATE TABLE');
        $insertEffect = $this->findEffect($ledger, 'INSERT INTO');
        $this->assertNotNull($createEffect);
        $this->assertNotNull($insertEffect);
        $this->assertLessThan($insertEffect->seq, $createEffect->seq, 'CREATE must be recorded before INSERT');
    }

    /**
     * exec()'s own return value (rows affected) is what QueryExecution
     * reports for a non-SELECT statement -- verified against the real value
     * Propulsion hands back, not an assumed one.
     */
    public function testExecEffectResultCarriesTheRealAffectedRowCount(): void
    {
        $ledger = $this->recordingLedger();

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $affected = $conn->exec("INSERT INTO items (name) VALUES ('a'), ('b')");

        $insertEffect = $this->findEffect($ledger, 'INSERT INTO');
        $this->assertNotNull($insertEffect);
        $this->assertSame(['row_count' => $affected], $insertEffect->result);
    }

    /**
     * A SELECT's row count is documented (both by PDOStatement and by
     * QueryExecution) as unreliable, so Propulsion reports null for it --
     * this recorder must carry that through rather than inventing a number.
     * Rows themselves ARE captured now (unlike before effects were wired in
     * live), since a SELECT/statement source always requests row capture.
     *
     * Uses prepare()+execute(), not $conn->query(): verified (not assumed) that
     * PropulsionPDO::query() builds and notifies a QueryExecution but never attaches it to the
     * returned statement's $currentExecution, so row capture can never fire for a
     * query()-sourced statement -- a real Propulsion gap, but one that doesn't affect the ORM's
     * own generated code, which always binds through prepare()/bindValue()/execute()
     * (BasePeer::doSelect() et al.), never raw query().
     */
    public function testSelectEffectResultCarriesNullRowCountAndTheCapturedRows(): void
    {
        $ledger = $this->recordingLedger();

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $conn->exec("INSERT INTO items (name) VALUES ('quiote')");
        $stmt = $conn->prepare('SELECT name FROM items');
        $this->assertNotFalse($stmt);
        $stmt->execute();
        // A fetch() loop, not fetchAll(): row capture hooks PropulsionStatement::fetch()
        // itself (mirroring the ORM's own default formatter, per docs/OBSERVABILITY.md), which
        // fetchAll() does not route through.
        while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
        }

        $selectEffect = $this->findEffect($ledger, 'SELECT name FROM items');
        $this->assertNotNull($selectEffect);
        $result = $selectEffect->result;
        $this->assertIsArray($result);
        $this->assertNull($result['row_count']);
        $rows = $result['rows'];
        $this->assertIsArray($rows);
        $this->assertSame(['quiote'], $rows[0]);
        $this->assertSame(['name'], $result['columns']);
        $this->assertFalse($result['truncated']);
    }

    /**
     * Regression: PropulsionPDO::query() builds and notifies a QueryExecution but never attaches
     * it to the returned statement, so rowsCaptured() can never fire for a query()-sourced
     * statement -- requesting capture for one anyway would make it wait forever and the query
     * would never appear in the ledger at all. It must still be recorded, just without rows.
     */
    public function testAQuerySourcedSelectIsStillRecordedWithoutRows(): void
    {
        $ledger = $this->recordingLedger();

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $conn->exec("INSERT INTO items (name) VALUES ('quiote')");
        $stmt = $conn->query('SELECT name FROM items');
        $this->assertNotFalse($stmt);
        while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
        }

        $effect = $this->findEffect($ledger, 'SELECT name FROM items');
        $this->assertNotNull($effect);
        $this->assertSame(['row_count' => null], $effect->result);
    }

    public function testAFailingQueryDoesNotProduceALedgerEntry(): void
    {
        $ledger = $this->recordingLedger();

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
        $ledger = $this->recordingLedger();

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $effect = $ledger->all()[0];
        $this->assertNotNull($effect->durationMicros);
        $this->assertGreaterThanOrEqual(0, $effect->durationMicros);
    }

    public function testNoEffectIsRecordedWhenNoRequestIsRecording(): void
    {
        // Registered, but no correlation id was ever set and no ledger is registered for one --
        // this is the "nothing is recording this request" branch every real, non-sampled request
        // takes.
        Propulsion::addQueryObserver(new PropulsionQueryRecorder(static fn(): Redactor => new Redactor([], [], [])));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $this->assertSame([], EffectLedgerRegistry::get(self::CORRELATION_ID)?->all() ?? []);
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

        $this->recordingLedger();
        $observedConn = $this->connect();
        $observedConn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $observedAffected = $observedConn->exec("INSERT INTO items (name) VALUES ('a')");
        $observedStmt = $observedConn->query('SELECT name FROM items');
        $this->assertNotFalse($observedStmt);
        $observedValue = $observedStmt->fetchColumn();

        $this->assertSame($unobservedAffected, $observedAffected);
        $this->assertSame($unobservedValue, $observedValue);
    }

    public function testBoundParamsAreCapturedWithTableAndColumn(): void
    {
        $ledger = $this->recordingLedger();

        $conn = $this->connect();
        $conn->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $conn->exec("INSERT INTO items (name) VALUES ('quiote')");
        $stmt = $conn->prepare('SELECT name FROM items WHERE id >= :p1');
        $this->assertNotFalse($stmt);
        $stmt->bindValue(':p1', 1);
        $stmt->execute();
        $stmt->fetchAll(PDO::FETCH_NUM);
        $stmt->closeCursor();

        $effect = $this->findEffect($ledger, 'WHERE id >= :p1');
        $this->assertNotNull($effect);
        $param = $this->boundParam($effect, ':p1');
        $this->assertSame(1, $param['value']);
        // Manual prepare()/bindValue() bypasses DBAdapter::bindValues(), so no table/column
        // identity is available here -- exactly the documented "raw/manual PDO" case.
        $this->assertNull($param['table']);
        $this->assertNull($param['column']);
    }

    public function testADenylistedBoundParamValueIsRedacted(): void
    {
        $ledger = $this->recordingLedger(new Redactor([], ['password'], []));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password TEXT)');
        // Goes through the ORM's own Criteria/BasePeer path (via a raw INSERT built the same
        // shape DBAdapter::bindValues() expects) is more setup than a direct statement needs
        // here; the redaction call site is exercised precisely at the unit level in
        // RedactorTest, so this integration test only needs to prove PropulsionQueryRecorder
        // actually calls it -- using setPendingColumn() directly, the same seam
        // DBAdapter::bindValues() itself uses.
        $stmt = $conn->prepare('INSERT INTO users (password) VALUES (:p1)');
        $this->assertNotFalse($stmt);
        $this->assertInstanceOf(\Propulsion\Connection\PropulsionStatement::class, $stmt);
        $stmt->setPendingColumn('users', 'password');
        $stmt->bindValue(':p1', 'super-secret');
        $stmt->execute();

        $effect = $this->findEffect($ledger, 'INSERT INTO users');
        $this->assertNotNull($effect);
        $param = $this->boundParam($effect, ':p1');
        $this->assertSame('[REDACTED]', $param['value']);
        $this->assertSame('password', $param['column']);
    }

    public function testCapturedRowsAreRedactedByColumnName(): void
    {
        $ledger = $this->recordingLedger(new Redactor([], ['password'], []));

        $conn = $this->connect();
        $conn->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, password TEXT)');
        $conn->exec("INSERT INTO users (name, password) VALUES ('ada', 'super-secret')");
        $stmt = $conn->prepare('SELECT name, password FROM users');
        $this->assertNotFalse($stmt);
        $stmt->execute();
        while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
        }

        $effect = $this->findEffect($ledger, 'SELECT name, password FROM users');
        $this->assertNotNull($effect);
        $result = $effect->result;
        $this->assertIsArray($result);
        $rows = $result['rows'];
        $this->assertIsArray($rows);
        $this->assertSame(['ada', '[REDACTED]'], $rows[0]);
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
