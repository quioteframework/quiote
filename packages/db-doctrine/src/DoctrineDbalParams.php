<?php

namespace Quiote\Database\Adapter\Doctrine;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Quiote\Exception\DatabaseException;

/**
 * Shared translation of flat `databases.xml` parameters into a Doctrine DBAL
 * connection-parameters array, used by both {@see DoctrineDatabase} and
 * {@see DoctrineDbalDatabase}.
 *
 * DBAL 4's DriverManager no longer parses a `url` parameter itself (that
 * moved to {@see DsnParser}), and no longer accepts an arbitrary connection
 * array -- it expects a closed parameter shape with per-key types. So a `url`
 * is parsed up front, and flat params are validated/typed one key at a time
 * rather than assembled with `array_filter()`.
 *
 * @phpstan-import-type Params from DriverManager
 */
trait DoctrineDbalParams
{
    /**
     * @return Params
     */
    protected function dbalParams(): array
    {
        $url = $this->getParameter('url');
        if (is_string($url) && $url !== '') {
            return (new DsnParser())->parse($url);
        }

        $params = [];

        $driver = $this->getParameter('driver');
        if ($driver !== null) {
            $params['driver'] = match ($driver) {
                'pdo_mysql', 'pdo_sqlite', 'pdo_pgsql', 'pdo_oci', 'oci8', 'ibm_db2',
                'pdo_sqlsrv', 'mysqli', 'pgsql', 'sqlsrv', 'sqlite3' => $driver,
                default => throw new DatabaseException(sprintf(
                    '%s "%s": unsupported "driver" parameter (%s). Supported drivers: %s.',
                    static::class,
                    $this->getName(),
                    get_debug_type($driver),
                    implode(', ', DriverManager::getAvailableDrivers())
                )),
            };
        }

        $host = $this->getParameter('host');
        if ($host !== null) {
            $params['host'] = $this->requireDbalParamString($host, 'host');
        }

        $port = $this->getParameter('port');
        if ($port !== null) {
            $params['port'] = $this->requireDbalParamInt($port, 'port');
        }

        $dbname = $this->getParameter('dbname');
        if ($dbname !== null) {
            $params['dbname'] = $this->requireDbalParamString($dbname, 'dbname');
        }

        $user = $this->getParameter('user', $this->getParameter('username'));
        if ($user !== null) {
            $params['user'] = $this->requireDbalParamString($user, 'user');
        }

        $password = $this->getParameter('password');
        if ($password !== null) {
            $params['password'] = $this->requireDbalParamString($password, 'password');
        }

        $path = $this->getParameter('path');
        if ($path !== null) {
            $params['path'] = $this->requireDbalParamString($path, 'path');
        }

        $memory = $this->getParameter('memory');
        if ($memory !== null) {
            $params['memory'] = $this->requireDbalParamBool($memory, 'memory');
        }

        $charset = $this->getParameter('charset');
        if ($charset !== null) {
            $params['charset'] = $this->requireDbalParamString($charset, 'charset');
        }

        return $params;
    }

    private function requireDbalParamString(mixed $value, string $paramName): string
    {
        if (!is_string($value)) {
            throw new DatabaseException(sprintf(
                '%s "%s": "%s" parameter must be a string, got %s.',
                static::class,
                $this->getName(),
                $paramName,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    private function requireDbalParamInt(mixed $value, string $paramName): int
    {
        if (!is_int($value)) {
            throw new DatabaseException(sprintf(
                '%s "%s": "%s" parameter must be an integer, got %s.',
                static::class,
                $this->getName(),
                $paramName,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    private function requireDbalParamBool(mixed $value, string $paramName): bool
    {
        if (!is_bool($value)) {
            throw new DatabaseException(sprintf(
                '%s "%s": "%s" parameter must be a boolean, got %s.',
                static::class,
                $this->getName(),
                $paramName,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    /**
     * Validates and re-types an inline `connection` array (as opposed to the
     * flat params handled by {@see dbalParams()}) into DBAL's expected
     * parameter shape. `primary`/`replica` (master/replica overrides) are
     * intentionally rejected -- configure those connections separately.
     *
     * @return Params
     */
    protected function normalizeInlineDbalParams(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw new DatabaseException(sprintf(
                '%s "%s": an inline "connection" parameter must be an array.',
                static::class,
                $this->getName()
            ));
        }

        $params = [];

        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                throw new DatabaseException(sprintf(
                    '%s "%s": inline "connection" array keys must be strings, got %s.',
                    static::class,
                    $this->getName(),
                    get_debug_type($key)
                ));
            }

            match ($key) {
                'driver' => $params['driver'] = match ($value) {
                    'pdo_mysql', 'pdo_sqlite', 'pdo_pgsql', 'pdo_oci', 'oci8', 'ibm_db2',
                    'pdo_sqlsrv', 'mysqli', 'pgsql', 'sqlsrv', 'sqlite3' => $value,
                    default => throw new DatabaseException(sprintf(
                        '%s "%s": unsupported "driver" value (%s) in inline "connection" array. '
                        . 'Supported drivers: %s.',
                        static::class,
                        $this->getName(),
                        get_debug_type($value),
                        implode(', ', DriverManager::getAvailableDrivers())
                    )),
                },
                'application_name', 'charset', 'dbname', 'host', 'password', 'path',
                'serverVersion', 'user', 'unix_socket' => $params[$key] = $this->requireDbalParamString($value, $key),
                'port', 'sessionMode' => $params[$key] = $this->requireDbalParamInt($value, $key),
                'memory', 'persistent' => $params[$key] = $this->requireDbalParamBool($value, $key),
                'driverOptions' => $params['driverOptions'] = is_array($value) ? $value : throw new DatabaseException(sprintf(
                    '%s "%s": "driverOptions" in inline "connection" array must be an array, got %s.',
                    static::class,
                    $this->getName(),
                    get_debug_type($value)
                )),
                'defaultTableOptions' => $params['defaultTableOptions'] = $this->requireDbalParamStringKeyedArray($value, 'defaultTableOptions'),
                'driverClass' => $params['driverClass'] = $this->requireDbalParamClassString($value, 'driverClass', \Doctrine\DBAL\Driver::class),
                'wrapperClass' => $params['wrapperClass'] = $this->requireDbalParamClassString($value, 'wrapperClass', DbalConnection::class),
                default => throw new DatabaseException(sprintf(
                    '%s "%s": inline "connection" array key "%s" is not supported. Configure '
                    . '"primary"/"replica" overrides directly against Doctrine\DBAL\DriverManager instead.',
                    static::class,
                    $this->getName(),
                    $key
                )),
            };
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireDbalParamStringKeyedArray(mixed $value, string $paramName): array
    {
        if (!is_array($value)) {
            throw new DatabaseException(sprintf(
                '%s "%s": "%s" parameter must be an array, got %s.',
                static::class,
                $this->getName(),
                $paramName,
                get_debug_type($value)
            ));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new DatabaseException(sprintf(
                    '%s "%s": "%s" parameter must have string keys, got %s.',
                    static::class,
                    $this->getName(),
                    $paramName,
                    get_debug_type($key)
                ));
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * @template T of object
     * @param class-string<T> $mustBeInstanceOf
     * @return class-string<T>
     */
    private function requireDbalParamClassString(mixed $value, string $paramName, string $mustBeInstanceOf): string
    {
        if (!is_string($value) || !is_a($value, $mustBeInstanceOf, true)) {
            throw new DatabaseException(sprintf(
                '%s "%s": "%s" parameter must be a class-string of %s, got %s.',
                static::class,
                $this->getName(),
                $paramName,
                $mustBeInstanceOf,
                get_debug_type($value)
            ));
        }

        return $value;
    }
}
