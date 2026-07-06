# Raw PDO access across database adapters

Every `Database` adapter exposes `getPdo(): \PDO` for callers who need to
hand-write SQL — a custom query, a driver-specific optimization the ORM's
query builder can't express, or code shared with something that already
speaks PDO. This document explains what it returns per adapter and why.

## Why this exists

`Database::getConnection()` intentionally returns each adapter's *native*
object — a `\PDO` for the plain PDO adapter, but an Eloquent `Capsule`, a
Doctrine `EntityManager`/`Connection`, or a Cycle `ORM` for the ORM adapters.
That's a deliberate design choice (see `docs/DATABASE.md`): `getConnection()`
returns the thing you configured, and typed accessors (`getCapsule()`,
`getEntityManager()`, `getOrm()`, ...) are how you get at ORM-specific
functionality. Overloading `getConnection()`'s return type per adapter would
make it untypeable and unpredictable at call sites.

`getPdo()` is the same kind of typed accessor, standardized across every
adapter, for the one thing that's useful uniformly: a raw PDO handle. Call it
on the `Database` wrapper itself:

```php
$pdo = $context->getDatabaseManager()->getDatabase('main')->getPdo();
$stmt = $pdo->prepare('SELECT * FROM orders WHERE status = ?');
$stmt->execute(['shipped']);
```

Because `getPdo(): \PDO` is declared on the `Database` base class itself (not
an interface implemented conditionally), PHPStan and IDEs know the return
type without any `@var` annotations or `instanceof` narrowing at the call
site — every adapter either returns a real `\PDO` or throws a
`DatabaseException` explaining why it can't.

## Support matrix

| Adapter                 | `getPdo()`                                                            |
|--------------------------|------------------------------------------------------------------------|
| `PdoDatabase`            | Always works — `getConnection()` already *is* the `\PDO`.              |
| `PropulsionDatabase`     | Always works — returns the `PropulsionPDO` (`extends \PDO`) instance.  |
| `EloquentDatabase`       | Always works — Illuminate's connectors always build a `\PDO` internally, in both standalone and layer mode. |
| `DoctrineDbalDatabase`   | Works **only** if the configured `driver` is a `pdo_*` one (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, ...). Throws for native drivers (`mysqli`, `pgsql`, `sqlite3`, ...). |
| `DoctrineDatabase`       | Same rule as `DoctrineDbalDatabase` — it unwraps the ORM's underlying DBAL connection. |
| `CycleDatabase`          | **Always throws.** Cycle never exposes a raw PDO publicly (see below). |

## Doctrine DBAL 4: native drivers vs. PDO drivers

DBAL 4 dropped its historical PDO-only design. It now ships both native
extension drivers (`mysqli`, `pgsql`) and PDO-wrapping drivers (`pdo_mysql`,
`pdo_pgsql`, `pdo_sqlite`, `pdo_sqlsrv`, `pdo_oci`) side by side, selected via
the `driver` connection parameter. `Doctrine\DBAL\Connection::getNativeConnection()`
returns whichever one you picked — a `\PDO` for `pdo_*` drivers, or a
`\mysqli`/`\PgSql\Connection`/etc. for native ones. There's no way to force a
native connection to become a PDO after the fact.

**If you need `getPdo()` to work on a Doctrine-backed database, configure it
with a `pdo_*` driver.** This is also why `DoctrineDatabase`'s layer mode
(reusing another database's connection) requires referencing a
`DoctrineDbalDatabase` by name rather than a plain `PdoDatabase` — DBAL 4
cannot wrap a pre-existing PDO instance, only build its own from connection
parameters.

If you've configured a native driver deliberately (e.g. for `mysqli`-specific
features or to avoid PDO's overhead) and need custom SQL, use DBAL's own raw
SQL API instead of `getPdo()`:

```php
$dbal = $db->getDbalConnection();
$rows = $dbal->fetchAllAssociative('SELECT * FROM orders WHERE status = ?', ['shipped']);
$dbal->executeStatement('UPDATE orders SET status = ? WHERE id = ?', ['cancelled', $id]);
```

## Cycle ORM: there is no raw PDO, by design

Cycle's `Driver` class deliberately keeps its connection internal:
`Driver::getPDO()` is `protected`, and its own return type is
`\PDO|PDOInterface` — Cycle allows a driver to be backed by something other
than PDO entirely (e.g. a pooled/proxy connection), so there is no public,
type-safe way to reach through to a `\PDO` even when one happens to be there
under the hood. Reflecting into a third-party library's protected internals
to work around that would be fragile and break silently on a Cycle upgrade,
so `CycleDatabase::getPdo()` always throws a `DatabaseException` pointing at
the alternative.

**For custom or optimized SQL with Cycle, use Cycle's own escape hatches
instead of dropping to PDO:**

- **`Cycle\Database\Injection\Fragment` / `Expression`** — inject a raw SQL
  snippet into an otherwise query-builder-driven call, still parameterized.
  Use this when most of the query is builder-generated and only one
  expression needs to be database-specific:
  ```php
  $select->where('created_at', '>', new Fragment('NOW() - INTERVAL 1 DAY'));
  ```
- **`DatabaseInterface::query()` / `execute()`** — fully hand-written,
  parameterized SQL through Cycle's own driver (still gets Cycle's parameter
  binding, logging, and transaction handling):
  ```php
  $database = $db->getCycleDatabaseManager()->database();
  $rows = $database->query('SELECT * FROM orders WHERE status = ?', ['shipped'])->fetchAll();
  $affected = $database->execute('UPDATE orders SET status = ? WHERE id = ?', ['cancelled', $id]);
  ```

This is the equivalent of `PDO::prepare()->execute()` for Cycle, and is the
intended way to write custom SQL against a Cycle-backed database — not a
workaround for a missing feature.
