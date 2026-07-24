<?php

namespace Quiote\Queue\Db;

use PDO;
use Quiote\Queue\FailedJob;
use Quiote\Queue\FailedJobRecord;
use Quiote\Queue\FailedJobStoreInterface;
use Quiote\Queue\InspectableFailedJobStoreInterface;

/**
 * Persistent {@see FailedJobStoreInterface} — an inspectable dead-letter
 * table, alternative to the default {@see \Quiote\Queue\LogFailedJobStore}.
 * Implements {@see InspectableFailedJobStoreInterface} so `queue:failed:list`/
 * `queue:failed:retry`/`queue:failed:forget` can query it.
 *
 * Schema (see {@see self::schema()}):
 *   CREATE TABLE quiote_queue_failed_jobs (
 *       id                 VARCHAR(32)  PRIMARY KEY,
 *       job_class          VARCHAR(255) NOT NULL,
 *       params             TEXT         NOT NULL,
 *       exception_class    VARCHAR(255) NOT NULL,
 *       exception_message  TEXT         NOT NULL,
 *       exception_trace    TEXT         NOT NULL,
 *       attempts           INTEGER      NOT NULL,
 *       failed_at          INTEGER      NOT NULL
 *   );
 */
final readonly class DbFailedJobStore implements InspectableFailedJobStoreInterface
{
    public function __construct(
        private PDO $pdo,
        private string $table = 'quiote_queue_failed_jobs',
    ) {
    }

    public function record(FailedJob $failedJob): void
    {
        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (id, job_class, params, exception_class, exception_message, exception_trace, attempts, failed_at)'
            . ' VALUES (:id, :job_class, :params, :exception_class, :exception_message, :exception_trace, :attempts, :failed_at)',
            $this->quoteIdent($this->table),
        ));
        $stmt->execute([
            'id' => bin2hex(random_bytes(16)),
            'job_class' => $failedJob->jobClass,
            'params' => json_encode($failedJob->params, JSON_THROW_ON_ERROR),
            'exception_class' => $failedJob->exceptionClass,
            'exception_message' => $failedJob->exceptionMessage,
            'exception_trace' => $failedJob->exceptionTrace,
            'attempts' => $failedJob->attempts,
            'failed_at' => time(),
        ]);
    }

    /** @return list<FailedJobRecord> */
    public function list(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s ORDER BY failed_at DESC, id LIMIT :limit OFFSET :offset',
            $this->quoteIdent($this->table),
        ));
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $records = [];
        while (is_array($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $records[] = $this->mapRow($row);
        }
        return $records;
    }

    public function count(): int
    {
        $stmt = $this->pdo->query(sprintf('SELECT COUNT(*) FROM %s', $this->quoteIdent($this->table)));
        if ($stmt === false) {
            throw new \RuntimeException(sprintf('Failed to count rows in "%s".', $this->table));
        }
        return (int) $stmt->fetchColumn();
    }

    public function find(string $id): ?FailedJobRecord
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE id = :id', $this->quoteIdent($this->table)));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE id = :id', $this->quoteIdent($this->table)));
        $stmt->execute(['id' => $id]);
    }

    /** @param array<mixed, mixed> $row */
    private function mapRow(array $row): FailedJobRecord
    {
        $decoded = json_decode($this->requireString($row, 'params'), true, flags: JSON_THROW_ON_ERROR);
        $params = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $params[(string) $key] = $value;
            }
        }

        return new FailedJobRecord(
            id: $this->requireString($row, 'id'),
            jobClass: $this->requireString($row, 'job_class'),
            params: $params,
            exceptionClass: $this->requireString($row, 'exception_class'),
            exceptionMessage: $this->requireString($row, 'exception_message'),
            exceptionTrace: $this->requireString($row, 'exception_trace'),
            attempts: $this->requireInt($row, 'attempts'),
            failedAt: new \DateTimeImmutable('@' . $this->requireInt($row, 'failed_at')),
        );
    }

    /** @param array<mixed, mixed> $row */
    private function requireString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Failed-jobs table "%s" row has a non-string "%s" column.', $this->table, $field));
        }
        return $value;
    }

    /** @param array<mixed, mixed> $row */
    private function requireInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException(sprintf('Failed-jobs table "%s" row has a non-numeric "%s" column.', $this->table, $field));
        }
        return (int) $value;
    }

    /** DDL to create the backing table (PostgreSQL / SQLite compatible). */
    public static function schema(string $table = 'quiote_queue_failed_jobs'): string
    {
        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
            . ' id VARCHAR(32) NOT NULL PRIMARY KEY,'
            . ' job_class VARCHAR(255) NOT NULL,'
            . ' params TEXT NOT NULL,'
            . ' exception_class VARCHAR(255) NOT NULL,'
            . ' exception_message TEXT NOT NULL,'
            . ' exception_trace TEXT NOT NULL,'
            . ' attempts INTEGER NOT NULL,'
            . ' failed_at INTEGER NOT NULL'
            . ')',
            $table,
        );
    }

    private function quoteIdent(string $ident): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
            throw new \InvalidArgumentException('Invalid failed-jobs table name: ' . $ident);
        }
        return $ident;
    }
}
