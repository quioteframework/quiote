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
        $connection = $this->getContext()->getDatabaseConnection($this->getParameter('database'));

        if (!$connection instanceof PDO) {
            throw new DatabaseException(sprintf(
                'Database connection "%s" could not be found or is not a PDO connection.',
                (string) $this->getParameter('database'),
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
        if ($this->connection === null) {
            return false;
        }

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $this->getParameter('db_data_col', 'sess_data'),
            $this->getParameter('db_table'),
            $this->getParameter('db_id_col', 'sess_id'),
        );

        try {
            $statement = $this->connection->prepare($sql);
            $statement->execute([$key]);
            $row = $statement->fetch(PDO::FETCH_NUM);
        } catch (PDOException $e) {
            throw $this->wrap($e);
        }

        if ($row === false) {
            return '';
        }

        $data = $row[0];

        return is_resource($data) ? stream_get_contents($data) : (string) $data;
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $table = $this->getParameter('db_table');
        $idCol = $this->getParameter('db_id_col', 'sess_id');
        $dataCol = $this->getParameter('db_data_col', 'sess_data');
        $timeCol = $this->getParameter('db_time_col', 'sess_time');
        $useLob = (bool) $this->getParameter('data_as_lob', true);
        $timestamp = date($this->getParameter('date_format', 'U'));
        $timestamp = is_numeric($timestamp) ? (int) $timestamp : $timestamp;

        $bind = static function (\PDOStatement $statement) use ($data, $timestamp, $useLob): void {
            $statement->bindValue(':data', $data, $useLob ? PDO::PARAM_LOB : PDO::PARAM_STR);
            $statement->bindValue(':time', $timestamp, is_int($timestamp) ? PDO::PARAM_INT : PDO::PARAM_STR);
        };

        try {
            $insert = $this->connection->prepare(sprintf(
                'INSERT INTO %s (%s, %s, %s) VALUES (:id, :data, :time)',
                $table,
                $idCol,
                $dataCol,
                $timeCol,
            ));
            $insert->bindValue(':id', $id);
            $bind($insert);
            $this->connection->beginTransaction();
            $insert->execute();
            $this->connection->commit();

            return true;
        } catch (PDOException) {
            $this->connection->rollBack();
        }

        try {
            $update = $this->connection->prepare(sprintf(
                'UPDATE %s SET %s = :data, %s = :time WHERE %s = :id',
                $table,
                $dataCol,
                $timeCol,
                $idCol,
            ));
            $update->bindValue(':id', $id);
            $bind($update);
            $this->connection->beginTransaction();
            $update->execute();
            $this->connection->commit();

            return true;
        } catch (PDOException $e) {
            $this->connection->rollBack();
            throw $this->wrap($e);
        }
    }

    #[\Override]
    public function destroy($sessionId): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->getParameter('db_table'),
            $this->getParameter('db_id_col', 'sess_id'),
        );

        try {
            $this->connection->prepare($sql)->execute([$sessionId]);

            return true;
        } catch (PDOException $e) {
            throw $this->wrap($e);
        }
    }

    #[\Override]
    public function gc(int $maxlifetime): int|false
    {
        if ($this->connection === null) {
            return false;
        }

        $cutoff = date($this->getParameter('date_format', 'U'), time() - $maxlifetime);
        $sql = sprintf(
            'DELETE FROM %s WHERE %s < :time',
            $this->getParameter('db_table'),
            $this->getParameter('db_time_col', 'sess_time'),
        );

        try {
            $statement = $this->connection->prepare($sql);
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
