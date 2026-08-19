<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Pdo;

use PDO;
use PDOException;
use Quiote\Exception\StorageException;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Store\ListableCassetteStoreInterface;

/**
 * A PDO-backed {@see ListableCassetteStoreInterface}: a pod's filesystem does
 * not survive a restart/eviction, so a team without an object-store backend
 * keeps cassettes in the database it already has instead.
 *
 * Portable across PostgreSQL and SQLite only (`INSERT ... ON CONFLICT`,
 * matching {@see \Quiote\Security\RateLimit\PdoRateLimiterStorage}'s and
 * `queue-db`'s own documented scope for this class of hand-rolled SQL) --
 * MySQL/MariaDB support would need `ON DUPLICATE KEY UPDATE` instead and is
 * not implemented. The gzip-encoded cassette payload {@see CassetteCodec}
 * produces is not valid UTF-8, so it is base64-encoded into a plain `TEXT`
 * column rather than a driver-specific `BYTEA`/`BLOB` type -- the same
 * portability trick `PdoRateLimiterStorage::save()` already uses for its own
 * binary-ish payload, needed because a single `CREATE TABLE` string cannot
 * name a binary column type both engines accept.
 *
 * `recorded_at`/`route`/`status`/`trigger_reason` are extracted from the
 * cassette at write time into their own indexed-by-nothing-yet columns --
 * not because a query here uses them (`slugs()` still returns every id, and
 * `cassette:list`/`cassette:prune` decode-and-filter in PHP exactly as they
 * do against {@see \Quiote\Replay\Store\FileCassetteStore}, so both stores
 * share one filtering implementation) but so the raw table is legible and
 * directly queryable by hand (`SELECT * FROM quiote_cassettes WHERE status
 * >= 500`) without decoding a payload column first.
 *
 * Schema (see {@see self::schema()}):
 *   CREATE TABLE quiote_cassettes (
 *       slug           VARCHAR(64)  PRIMARY KEY,
 *       raw_id         VARCHAR(255) NOT NULL,
 *       recorded_at    VARCHAR(32)  NULL,
 *       route          VARCHAR(255) NULL,
 *       status         INTEGER      NULL,
 *       trigger_reason VARCHAR(32)  NULL,
 *       payload        TEXT         NOT NULL
 *   );
 */
final readonly class PdoCassetteStore implements ListableCassetteStoreInterface
{
    public function __construct(
        private PDO $pdo,
        private string $table = 'quiote_cassettes',
        private CassetteCodec $codec = new CassetteCodec(),
    ) {
        self::assertValidTableName($table);
    }

    /** @throws StorageException if the write does not succeed. */
    public function put(CassetteId $id, Cassette $cassette): void
    {
        $payload = base64_encode($this->codec->encode($cassette));
        $recordedAt = self::stringOrNull($cassette->meta['recorded_at'] ?? null);
        $route = self::stringOrNull($cassette->resolved['route'] ?? null);
        $status = is_int($cassette->response['status'] ?? null) ? $cassette->response['status'] : null;
        $triggerReason = self::stringOrNull($cassette->meta['trigger'] ?? null);

        /** @var array<string, array{0: mixed, 1: int}> $values */
        $values = [
            'raw_id' => [$id->raw, PDO::PARAM_STR],
            'recorded_at' => [$recordedAt, $recordedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR],
            'route' => [$route, $route === null ? PDO::PARAM_NULL : PDO::PARAM_STR],
            'status' => [$status, $status === null ? PDO::PARAM_NULL : PDO::PARAM_INT],
            'trigger_reason' => [$triggerReason, $triggerReason === null ? PDO::PARAM_NULL : PDO::PARAM_STR],
            'payload' => [$payload, PDO::PARAM_STR],
        ];

        $columns = array_merge(['slug'], array_keys($values));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (slug) DO UPDATE SET %s',
            $this->quoteIdent($this->table),
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ":$c", $columns)),
            implode(', ', array_map(static fn(string $c): string => "$c = :{$c}_upd", array_keys($values))),
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':slug', $id->slug, PDO::PARAM_STR);
            foreach ($values as $name => [$value, $type]) {
                $stmt->bindValue(":$name", $value, $type);
                $stmt->bindValue(":{$name}_upd", $value, $type);
            }
            $stmt->execute();
        } catch (PDOException $e) {
            throw new StorageException(sprintf('Failed writing cassette row for "%s": %s', $id->slug, $e->getMessage()), 0, $e);
        }
    }

    public function get(CassetteId $id): ?Cassette
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT payload FROM %s WHERE slug = :slug', $this->quoteIdent($this->table)));
        $stmt->bindValue(':slug', $id->slug, PDO::PARAM_STR);
        $stmt->execute();
        $payload = $stmt->fetchColumn();
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = base64_decode($payload, true);

        return $decoded === false ? null : $this->codec->decode($decoded);
    }

    public function has(CassetteId $id): bool
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT 1 FROM %s WHERE slug = :slug', $this->quoteIdent($this->table)));
        $stmt->bindValue(':slug', $id->slug, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function delete(CassetteId $id): void
    {
        $stmt = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE slug = :slug', $this->quoteIdent($this->table)));
        $stmt->bindValue(':slug', $id->slug, PDO::PARAM_STR);
        $stmt->execute();
    }

    /** @return list<string> */
    public function slugs(): array
    {
        $stmt = $this->pdo->query(sprintf('SELECT slug FROM %s ORDER BY slug', $this->quoteIdent($this->table)));
        if ($stmt === false) {
            return [];
        }

        $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter($slugs, is_string(...)));
    }

    /** DDL to create the backing table (PostgreSQL / SQLite compatible). */
    public static function schema(string $table = 'quiote_cassettes'): string
    {
        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
            . ' slug VARCHAR(64) NOT NULL PRIMARY KEY,'
            . ' raw_id VARCHAR(255) NOT NULL,'
            . ' recorded_at VARCHAR(32) NULL,'
            . ' route VARCHAR(255) NULL,'
            . ' status INTEGER NULL,'
            . ' trigger_reason VARCHAR(32) NULL,'
            . ' payload TEXT NOT NULL'
            . ')',
            $table,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Table name is developer-supplied config, not user input; still, only a safe identifier
     * shape is allowed, matching every other hand-rolled-SQL storage in this codebase
     * ({@see \Quiote\Security\RateLimit\PdoRateLimiterStorage::quoteIdent()},
     * `Quiote\Queue\Db\DbQueueDriver::quoteIdent()}).
     */
    private static function assertValidTableName(string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException('Invalid cassette table name: ' . $table);
        }
    }

    private function quoteIdent(string $ident): string
    {
        self::assertValidTableName($ident);

        return $ident;
    }
}
