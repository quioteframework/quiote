<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\DatabaseException;

/** A wrapper class DBAL will accept for the `wrapperClass` parameter. */
final class DoctrineDbalParamsTestWrapper extends DbalConnection
{
}

/**
 * The flat-params-to-DBAL translation shared by both Doctrine adapters:
 * `databases.xml` hands everything over untyped, so what reaches
 * DriverManager is entirely this trait's doing. DBAL connects lazily, so the
 * built connection's own getParams() can be read without a server.
 */
final class DoctrineDbalParamsTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!class_exists(DriverManager::class)) {
            $this->markTestSkipped('doctrine/dbal not installed');
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function paramsFor(array $parameters): array
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), $parameters);

        return $db->getDbalConnection()->getParams();
    }

    /** @param array<string, mixed> $parameters */
    private function assertRejects(array $parameters, string $expectedMessage): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), $parameters);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage($expectedMessage);

        $db->getDbalConnection();
    }

    // -- flat parameters ---------------------------------------------------

    public function testFlatParametersAreMappedOntoTheirDbalCounterparts(): void
    {
        $params = $this->paramsFor([
            'driver' => 'pdo_mysql',
            'host' => 'db.internal',
            'port' => 3307,
            'dbname' => 'app',
            'user' => 'app_user',
            'password' => 'secret',
            'charset' => 'utf8mb4',
        ]);

        $this->assertSame('pdo_mysql', $params['driver']);
        $this->assertSame('db.internal', $params['host']);
        $this->assertSame(3307, $params['port']);
        $this->assertSame('app', $params['dbname']);
        $this->assertSame('app_user', $params['user']);
        $this->assertSame('secret', $params['password']);
        $this->assertSame('utf8mb4', $params['charset']);
    }

    /**
     * Legacy `databases.xml` files say `username` where DBAL says `user`, and
     * both have to keep working.
     */
    public function testUsernameIsAcceptedAsAnAliasForUser(): void
    {
        $params = $this->paramsFor(['driver' => 'pdo_mysql', 'username' => 'legacy_user']);

        $this->assertSame('legacy_user', $params['user']);
    }

    public function testAnExplicitUserWinsOverUsername(): void
    {
        $params = $this->paramsFor([
            'driver' => 'pdo_mysql',
            'user' => 'preferred',
            'username' => 'legacy',
        ]);

        $this->assertSame('preferred', $params['user']);
    }

    public function testUnsetParametersAreOmittedRatherThanSentAsNull(): void
    {
        $params = $this->paramsFor(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->assertArrayNotHasKey('host', $params);
        $this->assertArrayNotHasKey('port', $params);
        $this->assertArrayNotHasKey('dbname', $params);
        $this->assertArrayNotHasKey('user', $params);
        $this->assertArrayNotHasKey('password', $params);
    }

    public function testTheSqlitePathAndMemoryParametersAreCarriedOver(): void
    {
        $file = $this->paramsFor(['driver' => 'pdo_sqlite', 'path' => '/var/db/app.sqlite']);
        $this->assertSame('/var/db/app.sqlite', $file['path']);

        $memory = $this->paramsFor(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->assertTrue($memory['memory']);
    }

    public function testAUrlIsParsedAndWinsOverFlatParameters(): void
    {
        $params = $this->paramsFor([
            'url' => 'pdo-mysql://url_user:url_pass@url.host:3308/url_db',
            'driver' => 'pdo_sqlite',
            'host' => 'ignored.host',
        ]);

        $this->assertSame('pdo_mysql', $params['driver']);
        $this->assertSame('url.host', $params['host']);
        $this->assertSame(3308, $params['port']);
        $this->assertSame('url_db', $params['dbname']);
        $this->assertSame('url_user', $params['user']);
    }

    /**
     * An empty `url` is what an unset environment placeholder expands to, so
     * it has to mean "not configured" and let the flat params through rather
     * than reach the DSN parser.
     */
    public function testAnEmptyUrlFallsThroughToTheFlatParameters(): void
    {
        $params = $this->paramsFor(['url' => '', 'driver' => 'pdo_sqlite', 'memory' => true]);

        $this->assertSame('pdo_sqlite', $params['driver']);
        $this->assertTrue($params['memory']);
    }

    public function testNoConnectionDetailsAtAllIsReportedAsSuch(): void
    {
        $this->assertRejects([], 'needs connection details');
    }

    public static function badlyTypedFlatParameters(): \Generator
    {
        yield 'port as a string' => [['driver' => 'pdo_mysql', 'port' => '3306'], '"port" parameter must be an integer'];
        yield 'dbname as an int' => [['driver' => 'pdo_mysql', 'dbname' => 42], '"dbname" parameter must be a string'];
        yield 'user as an array' => [['driver' => 'pdo_mysql', 'user' => ['a']], '"user" parameter must be a string'];
        yield 'password as an int' => [['driver' => 'pdo_mysql', 'password' => 1234], '"password" parameter must be a string'];
        yield 'path as an int' => [['driver' => 'pdo_sqlite', 'path' => 7], '"path" parameter must be a string'];
        yield 'memory as a string' => [['driver' => 'pdo_sqlite', 'memory' => 'yes'], '"memory" parameter must be a boolean'];
        yield 'charset as an int' => [['driver' => 'pdo_mysql', 'charset' => 8], '"charset" parameter must be a string'];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('badlyTypedFlatParameters')]
    public function testABadlyTypedFlatParameterIsRejectedByName(array $parameters, string $expectedMessage): void
    {
        $this->assertRejects($parameters, $expectedMessage);
    }

    public function testTheRejectionNamesTheAdapterAndTheConfiguredType(): void
    {
        $this->assertRejects(['driver' => 'pdo_mysql', 'port' => '3306'], 'got string');
    }

    // -- inline connection array -------------------------------------------

    public function testAnInlineConnectionArrayCarriesTheSupportedKeysThrough(): void
    {
        $params = $this->paramsFor([
            'connection' => [
                'driver' => 'pdo_mysql',
                'host' => 'db.internal',
                'port' => 3307,
                'dbname' => 'app',
                'user' => 'app_user',
                'password' => 'secret',
                'charset' => 'utf8mb4',
                'serverVersion' => '8.0.36',
                'application_name' => 'quiote',
                'unix_socket' => '/var/run/mysqld/mysqld.sock',
                'persistent' => true,
            ],
        ]);

        $this->assertSame('pdo_mysql', $params['driver']);
        $this->assertSame('db.internal', $params['host']);
        $this->assertSame(3307, $params['port']);
        $this->assertSame('8.0.36', $params['serverVersion']);
        $this->assertSame('quiote', $params['application_name']);
        $this->assertSame('/var/run/mysqld/mysqld.sock', $params['unix_socket']);
        $this->assertTrue($params['persistent']);
    }

    public function testAnInlineDriverOptionsArrayIsPassedToTheDriver(): void
    {
        $params = $this->paramsFor([
            'connection' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                'driverOptions' => [PDO::ATTR_CASE => PDO::CASE_LOWER],
            ],
        ]);

        $this->assertSame([PDO::ATTR_CASE => PDO::CASE_LOWER], $params['driverOptions']);
    }

    public function testAnInlineDefaultTableOptionsArrayIsCarriedThrough(): void
    {
        $params = $this->paramsFor([
            'connection' => [
                'driver' => 'pdo_mysql',
                'defaultTableOptions' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            ],
        ]);

        $this->assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'], $params['defaultTableOptions']);
    }

    public function testAnInlineWrapperClassIsUsedToBuildTheConnection(): void
    {
        $db = new DoctrineDbalDatabase();
        $db->initialize(new DatabaseManager(), [
            'connection' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                'wrapperClass' => DoctrineDbalParamsTestWrapper::class,
            ],
        ]);

        $this->assertInstanceOf(DoctrineDbalParamsTestWrapper::class, $db->getDbalConnection());
    }

    public static function badlyTypedInlineParameters(): \Generator
    {
        yield 'port as a string' => [['driver' => 'pdo_mysql', 'port' => '3306'], '"port" parameter must be an integer'];
        yield 'sessionMode as a string' => [['driver' => 'pdo_oci', 'sessionMode' => 'two'], '"sessionMode" parameter must be an integer'];
        yield 'persistent as a string' => [['driver' => 'pdo_mysql', 'persistent' => 'yes'], '"persistent" parameter must be a boolean'];
        yield 'serverVersion as a float' => [['driver' => 'pdo_mysql', 'serverVersion' => 8.0], '"serverVersion" parameter must be a string'];
        yield 'driverOptions as a string' => [['driver' => 'pdo_mysql', 'driverOptions' => 'nope'], '"driverOptions" in inline "connection" array must be an array'];
        yield 'defaultTableOptions as a string' => [['driver' => 'pdo_mysql', 'defaultTableOptions' => 'nope'], '"defaultTableOptions" parameter must be an array'];
        yield 'defaultTableOptions with int keys' => [['driver' => 'pdo_mysql', 'defaultTableOptions' => ['utf8mb4']], '"defaultTableOptions" parameter must have string keys'];
        yield 'driverClass that is not a driver' => [['driverClass' => stdClass::class], '"driverClass" parameter must be a class-string'];
        yield 'wrapperClass that is not a connection' => [['driver' => 'pdo_sqlite', 'memory' => true, 'wrapperClass' => stdClass::class], '"wrapperClass" parameter must be a class-string'];
    }

    /**
     * @param array<string, mixed> $connection
     */
    #[DataProvider('badlyTypedInlineParameters')]
    public function testABadlyTypedInlineParameterIsRejectedByName(array $connection, string $expectedMessage): void
    {
        $this->assertRejects(['connection' => $connection], $expectedMessage);
    }

    public function testAnInlineConnectionArrayRejectsNonStringKeys(): void
    {
        $this->assertRejects(
            ['connection' => ['driver' => 'pdo_sqlite', 5 => 'value']],
            'inline "connection" array keys must be strings',
        );
    }

    /**
     * `primary`/`replica` are DBAL's master-replica overrides, which need
     * their own nested parameter arrays; the message has to send the reader
     * somewhere rather than just say "unsupported".
     */
    public function testAnInlineReplicaOverrideIsRejectedWithSomewhereToGo(): void
    {
        $this->assertRejects(
            ['connection' => ['driver' => 'pdo_mysql', 'replica' => ['host' => 'replica.internal']]],
            'Configure "primary"/"replica" overrides directly against Doctrine\DBAL\DriverManager instead',
        );
    }

    public function testAnEmptyInlineConnectionArrayIsReportedAsNoDetails(): void
    {
        $this->assertRejects(['connection' => []], 'needs connection details');
    }
}
