<?php

declare(strict_types=1);

namespace Quiote\Storage\Pdo;

use PDO;
use PDOException;
use Quiote\Context;
use Quiote\Exception\DatabaseException;
use Quiote\Exception\InitializationException;
use Quiote\Storage\SessionStorage;

/**
 * Native `SessionHandlerInterface` storage backend (the legacy `Storage`
 * subsystem's mechanism, driven by PHP's own `$_SESSION`/`session_start()`)
 * that persists sessions to a database via PDO. For new code, prefer
 * {@see \Quiote\Session\SessionManager} with
 * {@see \Quiote\Session\Pdo\PdoSessionPersistence} instead — it's PSR-7-based
 * and safe under persistent worker runtimes; this class exists for apps
 * already built on the `Storage`/`SessionStorage` mechanism.
 *
 * Required parameter: `db_table`. Optional: `database` (connection name from
 * databases.xml), `db_id_col` (sess_id), `db_data_col` (sess_data),
 * `db_time_col` (sess_time), `data_as_lob` (true), `date_format` (U).
 */
final class PdoSessionStorage extends SessionStorage
{
    private ?PDO $connection = null;

    /**
     * Parameters arrive untyped from factories config, so every one that ends
     * up interpolated into SQL or passed to a typed function is narrowed here
     * rather than cast at the use site. Mirrors the same helper on
     * {@see \Quiote\Storage\PdoSessionStorage}.
     */
    private function stringParameter(string $name, string $default = ''): string
    {
        $value = $this->getParameter($name, $default);

        return is_string($value) ? $value : $default;
    }

    #[\Override]
    public function initialize(Context $context, array $parameters = [])
    {
        parent::initialize($context, $parameters);

        if (!$this->hasParameter('db_table')) {
            throw new InitializationException('PdoSessionStorage requires a "db_table" parameter.');
        }

        session_set_save_handler($this);
    }

    #[\Override]
    public function open($savePath, $sessionName): bool
    {
        $database = $this->getParameter('database');
        $name = is_string($database) ? $database : null;
        $connection = $this->getContext()->getDatabaseConnection($name);

        if (!$connection instanceof PDO) {
            throw new DatabaseException(sprintf(
                'Database connection "%s" could not be found or is not a PDO connection.',
                $name ?? '(default)',
            ));
        }

        $this->connection = $connection;

        return true;
    }

    #[\Override]
    public function close(): bool
    {
        return $this->connection !== null;
    }

    #[\Override]
    public function read(string $key): string|false
    {
        $connection = $this->connection;
        if ($connection === null) {
            return false;
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $this->stringParameter('db_data_col', 'sess_data'),
            $this->stringParameter('db_table'),
            $this->stringParameter('db_id_col', 'sess_id'),
        );

        $statement = null;

        try {
            // Narrowed through a separate variable so $statement -- which the
            // finally below dereferences -- is only ever PDOStatement|null.
            $prepared = $connection->prepare($sql);
            if ($prepared === false) {
                return false;
            }
            $statement = $prepared;
            $statement->execute([$key]);
            $row = $statement->fetch(PDO::FETCH_NUM);

            if (!is_array($row) || !array_key_exists(0, $row)) {
                return '';
            }

            $data = $row[0];

            // Drain a LOB stream here, while the cursor is still open.
            if (is_resource($data)) {
                $contents = stream_get_contents($data);

                return $contents === false ? '' : $contents;
            }

            return is_scalar($data) || $data instanceof \Stringable ? (string) $data : '';
        } catch (PDOException $e) {
            throw $this->wrap($e);
        } finally {
            // Release the cursor: a fetched-but-unclosed statement keeps the connection
            // inside an implicit read transaction holding a shared lock, and write()
            // opens an explicit transaction whose shared -> exclusive upgrade SQLite then
            // refuses immediately with SQLITE_BUSY (busy_timeout does not cover upgrades).
            // $statement is null when prepare() itself threw.
            $statement?->closeCursor();
        }
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        $connection = $this->connection;
        if ($connection === null) {
            return false;
        }

        $table = $this->stringParameter('db_table');
        $idCol = $this->stringParameter('db_id_col', 'sess_id');
        $dataCol = $this->stringParameter('db_data_col', 'sess_data');
        $timeCol = $this->stringParameter('db_time_col', 'sess_time');
        $useLob = (bool) $this->getParameter('data_as_lob', true);
        $timestampRaw = date($this->stringParameter('date_format', 'U'));
        $timestamp = is_numeric($timestampRaw) ? (int) $timestampRaw : $timestampRaw;

        $bind = static function (\PDOStatement $statement) use ($data, $timestamp, $useLob): void {
            $statement->bindValue(':data', $data, $useLob ? PDO::PARAM_LOB : PDO::PARAM_STR);
            $statement->bindValue(':time', $timestamp, is_int($timestamp) ? PDO::PARAM_INT : PDO::PARAM_STR);
        };

        try {
            $insert = $connection->prepare(sprintf(
                'INSERT INTO %s (%s, %s, %s) VALUES (:id, :data, :time)',
                $table,
                $idCol,
                $dataCol,
                $timeCol,
            ));
            if ($insert === false) {
                return false;
            }
            $insert->bindValue(':id', $id);
            $bind($insert);
            $connection->beginTransaction();
            $insert->execute();
            $connection->commit();

            return true;
        } catch (PDOException) {
            $connection->rollBack();
        }

        try {
            $update = $connection->prepare(sprintf(
                'UPDATE %s SET %s = :data, %s = :time WHERE %s = :id',
                $table,
                $dataCol,
                $timeCol,
                $idCol,
            ));
            if ($update === false) {
                return false;
            }
            $update->bindValue(':id', $id);
            $bind($update);
            $connection->beginTransaction();
            $update->execute();
            $connection->commit();

            return true;
        } catch (PDOException $e) {
            $connection->rollBack();
            throw $this->wrap($e);
        }
    }

    #[\Override]
    public function destroy($sessionId): bool
    {
        $connection = $this->connection;
        if ($connection === null) {
            return false;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->stringParameter('db_table'),
            $this->stringParameter('db_id_col', 'sess_id'),
        );

        try {
            $statement = $connection->prepare($sql);
            if ($statement === false) {
                return false;
            }
            $statement->execute([$sessionId]);

            return true;
        } catch (PDOException $e) {
            throw $this->wrap($e);
        }
    }

    #[\Override]
    public function gc(int $maxlifetime): int|false
    {
        $connection = $this->connection;
        if ($connection === null) {
            return false;
        }

        $cutoff = date($this->stringParameter('date_format', 'U'), time() - $maxlifetime);
        $sql = sprintf(
            'DELETE FROM %s WHERE %s < :time',
            $this->stringParameter('db_table'),
            $this->stringParameter('db_time_col', 'sess_time'),
        );

        try {
            $statement = $connection->prepare($sql);
            if ($statement === false) {
                return false;
            }
            $statement->bindValue(':time', is_numeric($cutoff) ? (int) $cutoff : $cutoff, is_numeric($cutoff) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $statement->execute();

            return $statement->rowCount();
        } catch (PDOException $e) {
            throw $this->wrap($e);
        }
    }

    private function wrap(PDOException $e): DatabaseException
    {
        return new DatabaseException('PDOException was thrown while manipulating session data: ' . $e->getMessage(), 0, $e);
    }
}
