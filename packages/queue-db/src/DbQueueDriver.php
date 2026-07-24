<?php

namespace Quiote\Queue\Db;

use PDO;
use Quiote\Queue\Job;
use Quiote\Queue\JobPayload;
use Quiote\Queue\PollableQueueDriverInterface;
use Quiote\Queue\ReservedJob;

/**
 * PDO-backed {@see PollableQueueDriverInterface}. Portable across PostgreSQL
 * and SQLite (no `SERIAL`/`AUTOINCREMENT`, no `FOR UPDATE SKIP LOCKED`) —
 * `id`/`reserved_token` are random hex strings rather than an autoincrement
 * key, following {@see \Quiote\Security\RateLimit\PdoRateLimiterStorage}'s
 * portability approach.
 *
 * `reserve()` claims a row via an UPDATE-then-SELECT-by-token pair rather
 * than `SELECT ... FOR UPDATE SKIP LOCKED`, so it works on both backends;
 * under heavy concurrent polling on PostgreSQL this is "reasonably safe",
 * not provably race-free — acceptable for v1, a documented limitation
 * rather than a silent one.
 *
 * Schema (see {@see self::schema()}):
 *   CREATE TABLE quiote_queue_jobs (
 *       id             VARCHAR(32)  PRIMARY KEY,
 *       job_class      VARCHAR(255) NOT NULL,
 *       params         TEXT         NOT NULL,
 *       attempts       INTEGER      NOT NULL DEFAULT 0,
 *       available_at   INTEGER      NOT NULL,
 *       reserved_at    INTEGER      NULL,
 *       reserved_token VARCHAR(32)  NULL
 *   );
 */
final readonly class DbQueueDriver implements PollableQueueDriverInterface
{
    public function __construct(
        private PDO $pdo,
        private string $table = 'quiote_queue_jobs',
    ) {
    }

    public function push(JobPayload $payload): void
    {
        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (id, job_class, params, attempts, available_at, reserved_at, reserved_token) VALUES (:id, :job_class, :params, :attempts, :available_at, NULL, NULL)',
            $this->quoteIdent($this->table),
        ));
        $stmt->execute([
            'id' => $this->randomId(),
            'job_class' => $payload->jobClass,
            'params' => json_encode($payload->params, JSON_THROW_ON_ERROR),
            'attempts' => $payload->attempts,
            'available_at' => $payload->availableAt?->getTimestamp() ?? time(),
        ]);
    }

    public function reserve(): ?ReservedJob
    {
        $now = time();
        $token = $this->randomId();

        $claim = $this->pdo->prepare(sprintf(
            'UPDATE %1$s SET reserved_at = :now, reserved_token = :token'
            . ' WHERE id = (SELECT id FROM %1$s WHERE available_at <= :now2 AND reserved_at IS NULL ORDER BY id LIMIT 1)',
            $this->quoteIdent($this->table),
        ));
        $claim->execute(['now' => $now, 'now2' => $now, 'token' => $token]);

        if ($claim->rowCount() === 0) {
            return null;
        }

        $select = $this->pdo->prepare(sprintf(
            'SELECT id, job_class, params, attempts FROM %s WHERE reserved_token = :token LIMIT 1',
            $this->quoteIdent($this->table),
        ));
        $select->execute(['token' => $token]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $id = $this->requireString($row, 'id');
        $jobClass = $this->requireString($row, 'job_class');
        if (!is_a($jobClass, Job::class, true)) {
            throw new \RuntimeException(sprintf('Queue table "%s" contains a job_class "%s" that does not implement %s.', $this->table, $jobClass, Job::class));
        }

        $decoded = json_decode($this->requireString($row, 'params'), true, flags: JSON_THROW_ON_ERROR);
        $params = $this->requireStringKeyedArray($decoded, $id);

        return new ReservedJob($id, new JobPayload($jobClass, $params, $this->requireInt($row, 'attempts')));
    }

    /** @param array<mixed, mixed> $row */
    private function requireString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Queue table "%s" row has a non-string "%s" column.', $this->table, $field));
        }
        return $value;
    }

    /** @param array<mixed, mixed> $row */
    private function requireInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException(sprintf('Queue table "%s" row has a non-numeric "%s" column.', $this->table, $field));
        }
        return (int) $value;
    }

    /** @return array<string, mixed> */
    private function requireStringKeyedArray(mixed $decoded, string $jobId): array
    {
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Queue table "%s" contains non-array params for job id "%s".', $this->table, $jobId));
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }
        return $result;
    }

    public function ack(ReservedJob $job): void
    {
        $this->deleteRow($job->id);
    }

    public function release(ReservedJob $job, int $delaySeconds): void
    {
        $stmt = $this->pdo->prepare(sprintf(
            'UPDATE %s SET reserved_at = NULL, reserved_token = NULL, available_at = :available_at, attempts = :attempts WHERE id = :id',
            $this->quoteIdent($this->table),
        ));
        $stmt->execute([
            'available_at' => time() + max(0, $delaySeconds),
            'attempts' => $job->payload->attempts + 1,
            'id' => $job->id,
        ]);
    }

    public function discard(ReservedJob $job): void
    {
        $this->deleteRow($job->id);
    }

    private function deleteRow(string $id): void
    {
        $stmt = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE id = :id', $this->quoteIdent($this->table)));
        $stmt->execute(['id' => $id]);
    }

    private function randomId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** DDL to create the backing table (PostgreSQL / SQLite compatible). */
    public static function schema(string $table = 'quiote_queue_jobs'): string
    {
        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
            . ' id VARCHAR(32) NOT NULL PRIMARY KEY,'
            . ' job_class VARCHAR(255) NOT NULL,'
            . ' params TEXT NOT NULL,'
            . ' attempts INTEGER NOT NULL DEFAULT 0,'
            . ' available_at INTEGER NOT NULL,'
            . ' reserved_at INTEGER NULL,'
            . ' reserved_token VARCHAR(32) NULL'
            . ')',
            $table,
        );
    }

    private function quoteIdent(string $ident): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
            throw new \InvalidArgumentException('Invalid queue table name: ' . $ident);
        }
        return $ident;
    }
}
