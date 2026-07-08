<?php

namespace Quiote\Test\Database;

use Testcontainers\Container\StartedTestContainer;
use Testcontainers\Modules\MySQLContainer;
use Testcontainers\Modules\PostgresContainer;

/**
 * Lazily starts (and, at process shutdown, stops) shared database containers for
 * the integration test suite. Containers are started at most once per test run
 * and reused across test classes; each test isolates itself by (re)creating its
 * own tables.
 *
 * All entry points return a plain connection-info array; callers that need to
 * skip when Docker or the relevant PDO driver is unavailable should consult
 * {@see dockerAvailable()} / {@see pdoDriver()} first (see
 * {@see IntegrationTestCase}).
 */
final class DatabaseContainers
{
    /** Label applied to every container we start, so orphans from a crashed run can be pruned. */
    private const LABEL = 'quiote.itest';

    /** @var array<string, array<string, mixed>> keyed by engine → info (+ '__container') */
    private static array $started = [];

    private static bool $shutdownRegistered = false;

    private static bool $orphansPruned = false;

    public static function dockerAvailable(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        if (!class_exists(MySQLContainer::class)) {
            return $available = false;
        }
        $rc = 1;
        $out = [];
        @exec('docker info > /dev/null 2>&1', $out, $rc);
        return $available = ($rc === 0);
    }

    /**
     * @return array{host:string,port:int,database:string,username:string,password:string,root_password:string}
     */
    public static function postgres(): array
    {
        return self::shapeConnectionInfo(self::remember('postgres', static function (): array {
            $db = 'quiote_test';
            $user = 'quiote';
            $pass = 'secret';
            self::ensureImage('postgres:16');
            $container = (new PostgresContainer('16', $user, $pass, $db))
                ->withLabels([self::LABEL => '1'])
                ->withAutoRemove(true)
                ->start();
            $info = [
                'host'          => $container->getHost(),
                'port'          => $container->getFirstMappedPort(),
                'database'      => $db,
                'username'      => $user,
                'password'      => $pass,
                'root_password' => $pass,
            ];
            self::awaitPdo(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', $info['host'], $info['port'], $db),
                $user,
                $pass
            );
            return ['__container' => $container] + $info;
        }));
    }

    /**
     * @return array{host:string,port:int,database:string,username:string,password:string,root_password:string}
     */
    public static function mysql(): array
    {
        return self::shapeConnectionInfo(self::remember('mysql', static function (): array {
            $db = 'quiote_test';
            $user = 'quiote';
            $pass = 'secret';
            $root = 'root';
            self::ensureImage('mysql:8.4');
            $container = (new MySQLContainer('8.4', $root))
                ->withMySQLDatabase($db)
                ->withMySQLUser($user, $pass)
                ->withLabels([self::LABEL => '1'])
                ->withAutoRemove(true)
                ->start();
            $info = [
                'host'          => $container->getHost(),
                'port'          => $container->getFirstMappedPort(),
                'database'      => $db,
                'username'      => $user,
                'password'      => $pass,
                'root_password' => $root,
            ];
            self::awaitPdo(
                sprintf('mysql:host=%s;port=%d;dbname=%s', $info['host'], $info['port'], $db),
                $user,
                $pass
            );
            return ['__container' => $container] + $info;
        }));
    }

    /** Whether a given PDO driver ("pgsql", "mysql", ...) is compiled into this PHP. */
    public static function pdoDriver(string $driver): bool
    {
        return in_array($driver, \PDO::getAvailableDrivers(), true);
    }

    /**
     * @param callable():array<string,mixed> $factory
     * @return array<string,mixed>
     */
    private static function remember(string $key, callable $factory): array
    {
        if (!isset(self::$started[$key])) {
            self::pruneOrphans();
            self::$started[$key] = $factory();
            self::registerShutdown();
        }
        $info = self::$started[$key];
        unset($info['__container']);
        return $info;
    }

    /**
     * @param array<string,mixed> $info
     * @return array{host:string,port:int,database:string,username:string,password:string,root_password:string}
     */
    private static function shapeConnectionInfo(array $info): array
    {
        foreach (['host', 'database', 'username', 'password', 'root_password'] as $key) {
            if (!isset($info[$key]) || !is_string($info[$key])) {
                throw new \RuntimeException(sprintf('Connection info key "%s" must be a string', $key));
            }
        }
        if (!isset($info['port']) || !is_int($info['port'])) {
            throw new \RuntimeException('Connection info key "port" must be an int');
        }
        return [
            'host' => $info['host'],
            'port' => $info['port'],
            'database' => $info['database'],
            'username' => $info['username'],
            'password' => $info['password'],
            'root_password' => $info['root_password'],
        ];
    }

    /**
     * The container's readiness probe (mysqladmin ping / pg_isready) reports the
     * server is up, but the app user/database may still be initialising — retry a
     * real PDO connection until it succeeds or the deadline passes.
     */
    private static function awaitPdo(string $dsn, string $user, string $pass, int $timeoutSeconds = 60): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastError = null;
        do {
            try {
                new \PDO($dsn, $user, $pass, [\PDO::ATTR_TIMEOUT => 2]);
                return;
            } catch (\PDOException $e) {
                $lastError = $e;
                usleep(500_000);
            }
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(
            'Database container did not become connectable within ' . $timeoutSeconds
            . 's: ' . $lastError->getMessage(),
            0,
            $lastError
        );
    }

    /**
     * Force-remove any containers left behind (in any state, including a
     * half-created "Created" container) by an earlier crashed run, so a fresh run
     * never collides with an orphan. Runs at most once per process.
     */
    private static function pruneOrphans(): void
    {
        if (self::$orphansPruned) {
            return;
        }
        self::$orphansPruned = true;
        @exec(
            'docker ps -aq --filter label=' . self::LABEL . ' 2>/dev/null | xargs -r docker rm -f > /dev/null 2>&1'
        );
    }

    /**
     * Pre-pull an image via the docker CLI if it isn't already local. The CLI
     * resolves registry credentials correctly (including Docker Desktop's
     * `credsStore: desktop.exe` on WSL2), whereas testcontainers-php's own auth
     * path fails on the credential helper even for public images. Once the image
     * is local, start() never needs to authenticate.
     */
    private static function ensureImage(string $image): void
    {
        $rc = 1;
        $out = [];
        @exec('docker image inspect ' . escapeshellarg($image) . ' > /dev/null 2>&1', $out, $rc);
        if ($rc !== 0) {
            @exec('docker pull ' . escapeshellarg($image) . ' > /dev/null 2>&1', $out, $rc);
        }
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$started as $entry) {
                $container = $entry['__container'] ?? null;
                if ($container instanceof StartedTestContainer) {
                    try {
                        $container->stop();
                    } catch (\Throwable) {
                        // best-effort teardown
                    }
                }
            }
        });
    }
}
