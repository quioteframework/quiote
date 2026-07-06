# Database & ORM connectors

Quiote ships one raw database driver out of the box — **PDO** — plus first-class
adapters that let `DatabaseManager::getDatabase()` hand back a fully-configured
ORM layer: **Eloquent**, **Doctrine ORM**, **Doctrine DBAL**, or **Cycle ORM**.
You declare a connection in configuration and Quiote instantiates and wires up
the chosen ORM; your application code just asks for the connection.

> Audience: application developers. For the design rationale and roadmap see
> `docs/DATABASE_ADAPTERS_PLAN.md`.

## Contents

- [Mental model](#mental-model)
- [Configuring connections](#configuring-connections)
- [Driver aliases](#driver-aliases)
- [Enabling an ORM adapter](#enabling-an-orm-adapter)
- [Built-in: PDO](#built-in-pdo)
- [Eloquent](#eloquent)
- [Doctrine ORM](#doctrine-orm)
- [Doctrine DBAL](#doctrine-dbal)
- [Cycle ORM](#cycle-orm)
- [Layer mode vs standalone mode](#layer-mode-vs-standalone-mode)
- [Using a connection in application code](#using-a-connection-in-application-code)
- [Worker mode & connection lifecycle](#worker-mode--connection-lifecycle)
- [Installing the libraries](#installing-the-libraries)
- [Integration testing with Testcontainers](#integration-testing-with-testcontainers)
- [Writing a custom adapter](#writing-a-custom-adapter)
- [Reference](#reference)

---

## Mental model

Each configured connection is a `Quiote\Database\Database` instance — a **lifecycle
wrapper**, not the connection itself. You obtain the underlying object through it:

```php
$db   = $context->getDatabaseManager()->getDatabase('main'); // a Database wrapper
$conn = $db->getConnection();                                // the PDO / ORM object
```

What `getConnection()` returns depends on the adapter:

| Adapter          | `getConnection()` returns                     |
|------------------|-----------------------------------------------|
| PDO              | `PDO`                                          |
| Eloquent         | `Illuminate\Database\Capsule\Manager`          |
| Doctrine ORM     | `Doctrine\ORM\EntityManagerInterface`          |
| Doctrine DBAL    | `Doctrine\DBAL\Connection`                     |
| Cycle ORM        | `Cycle\ORM\ORMInterface`                       |

The wrapper stays in front so the framework can manage the connection across a
long-lived worker's requests (ping, reconnect, reset). Each ORM adapter also
exposes **typed accessors** (`getEntityManager()`, `getCapsule()`, `getOrm()`, …)
so you get IDE completion — see each section below.

The `DatabaseManager` is registered in the DI container as the singleton
`databaseManager`, and `Context` exposes convenience accessors
(`getDatabaseManager()`, `getDatabaseConnection($name)`).

---

## Configuring connections

Connections live in a `databases.xml` (or `databases.php` / `databases.yaml`) file
in your app's `Config/` directory. Each `<database>` names an adapter via `class`
and passes free-form parameters.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations
    xmlns:ae="http://quiote.dev/quiote/config/envelope/1.1"
    xmlns="http://quiote.dev/quiote/config/parts/databases/1.1">
  <ae:configuration>
    <databases default="main">
      <database name="main" class="pdo">
        <ae:parameter name="dsn">pgsql:host=localhost;dbname=app</ae:parameter>
        <ae:parameter name="username">app</ae:parameter>
        <ae:parameter name="password">secret</ae:parameter>
      </database>
    </databases>
  </ae:configuration>
</ae:configurations>
```

- `default` selects the connection returned by `getDatabase()` with no argument.
- Multiple `<database>` children define multiple named connections.
- Per-environment overrides use `<ae:configuration environment="...">` blocks.

**Array parameters** nest `<ae:parameter>` elements (named → assoc, unnamed →
list):

```xml
<ae:parameter name="entity_paths">
  <ae:parameter>/srv/app/src/Entity</ae:parameter>
</ae:parameter>
```

**PHP config format.** Adapters whose parameters include PHP *objects* (notably
Cycle) can't be expressed in XML/YAML — use a `databases.php` that returns the
canonical array instead:

```php
<?php
return [
    'default'   => 'main',
    'databases' => [
        'main' => [
            'class'      => 'cycle',
            'parameters' => [ /* ... including config objects ... */ ],
        ],
    ],
];
```

---

## Driver aliases

`class` accepts either a fully-qualified adapter class name **or** a short driver
alias. Only `pdo` is built in; the ORM aliases are contributed by their plugins
(see next section).

| Alias           | Adapter class                                                  |
|-----------------|---------------------------------------------------------------|
| `pdo`           | `Quiote\Database\PdoDatabase`                                  |
| `eloquent`      | `Quiote\Database\Adapter\Eloquent\EloquentDatabase`           |
| `doctrine`      | `Quiote\Database\Adapter\Doctrine\DoctrineDatabase`           |
| `doctrine_dbal` | `Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase`       |
| `cycle`         | `Quiote\Database\Adapter\Cycle\CycleDatabase`                 |

> Aliases must be valid PHP labels — hence `doctrine_dbal`, not `doctrine-dbal`.
> A fully-qualified class name in `class` always works even without the plugin.

---

## Enabling an ORM adapter

Two steps:

1. **Install the library** (see [Installing the libraries](#installing-the-libraries)).
2. **Register the plugin** so its driver alias is available, by adding it to the
   `plugins` config key in your settings:

```php
// Config/settings.php
'plugins' => [
    \Quiote\Database\Adapter\Doctrine\DoctrinePlugin::class,
    \Quiote\Database\Adapter\Eloquent\EloquentPlugin::class,
    \Quiote\Database\Adapter\Cycle\CyclePlugin::class,
],
```

Without the plugin you can still reference the adapter by its full class name in
`class`. The plugin only adds the short alias.

---

## Built-in: PDO

`class="pdo"` → `Quiote\Database\PdoDatabase`. Always available (requires
`ext-pdo`).

| Parameter            | Default | Description                                             |
|----------------------|---------|---------------------------------------------------------|
| `dsn`                | —       | PDO DSN (required)                                       |
| `username`           | —       | Connection user                                         |
| `password`           | —       | Connection password                                     |
| `options`            | `[]`    | PDO driver options (constant names as strings allowed)  |
| `attributes`         | `[]`    | PDO attributes set after connect                        |
| `init_queries`       | `[]`    | Queries executed on connect                             |
| `warn_mysql_charset` | `true`  | Guards against unsafe `SET NAMES` on MySQL DSNs         |

`options`/`attributes` keys or values containing `::` are resolved as constants,
so you can write `PDO::ATTR_TIMEOUT`. `PDO::ATTR_ERRMODE` defaults to
`ERRMODE_EXCEPTION`.

```xml
<database name="main" class="pdo">
  <ae:parameter name="dsn">mysql:host=localhost;dbname=app;charset=utf8mb4</ae:parameter>
  <ae:parameter name="username">app</ae:parameter>
  <ae:parameter name="password">secret</ae:parameter>
  <ae:parameter name="attributes">
    <ae:parameter name="PDO::ATTR_TIMEOUT">2</ae:parameter>
  </ae:parameter>
</database>
```

---

## Eloquent

`class="eloquent"` → `EloquentDatabase`. Requires `illuminate/database` +
`EloquentPlugin`. `getConnection()` returns the Capsule Manager.

| Parameter         | Default        | Description                                                          |
|-------------------|----------------|---------------------------------------------------------------------|
| `connection`      | —              | Inline Eloquent config **array**, or the **name** of another database to borrow a live PDO from (layer mode). Omit for standalone flat params. |
| `driver`          | —              | `mysql` \| `pgsql` \| `sqlite` \| `sqlsrv` (required unless `connection` is an array supplying it) |
| `host`,`port`     | —              | Server address                                                      |
| `database`        | —              | Database name (`:memory:` for sqlite)                              |
| `username`,`password` | —          | Credentials                                                         |
| `charset`,`collation`,`prefix` | —   | Optional Eloquent connection options                              |
| `connection_name` | `default`      | Capsule connection name                                             |
| `global`          | `false`        | Call `setAsGlobal()` (needed for the `DB` facade)                   |
| `boot_eloquent`   | = `global`     | Call `bootEloquent()` (needed to use `Model` classes)              |

```xml
<database name="main" class="eloquent">
  <ae:parameter name="driver">pgsql</ae:parameter>
  <ae:parameter name="host">localhost</ae:parameter>
  <ae:parameter name="port">5432</ae:parameter>
  <ae:parameter name="database">app</ae:parameter>
  <ae:parameter name="username">app</ae:parameter>
  <ae:parameter name="password">secret</ae:parameter>
  <ae:parameter name="global">true</ae:parameter>
</database>
```

**Typed accessors:**

```php
$db = $context->getDatabaseManager()->getDatabase('main');
/** @var Quiote\Database\Adapter\Eloquent\EloquentDatabase $db */

$capsule = $db->getCapsule();              // Illuminate\Database\Capsule\Manager
$conn    = $db->getEloquentConnection();   // Illuminate\Database\Connection

$conn->table('users')->where('active', true)->get();
// With global + bootEloquent enabled, models work too:
User::where('active', true)->get();
```

---

## Doctrine ORM

`class="doctrine"` → `DoctrineDatabase` (Doctrine ORM 3 / DBAL 4). Requires
`doctrine/orm` + `DoctrinePlugin`. `getConnection()` returns the EntityManager.

| Parameter             | Default          | Description                                                    |
|-----------------------|------------------|----------------------------------------------------------------|
| `connection`          | —                | Name of a `doctrine_dbal` database to reuse, **or** an inline DBAL params array. Omit to build from flat params. |
| `url`                 | —                | DBAL DSN URL (alternative to flat params)                      |
| `driver`              | —                | `pdo_mysql` \| `pdo_pgsql` \| `pdo_sqlite` \| …                |
| `host`,`port`,`dbname`| —                | Server + database                                              |
| `user` / `username`   | —                | Credentials (`user` preferred; `username` accepted)           |
| `password`            | —                | Password                                                       |
| `path`,`memory`,`charset` | —            | sqlite file / `:memory:` / charset                            |
| `entity_paths`        | `[]`             | Directories/files holding mapping metadata                     |
| `metadata`            | `attribute`      | `attribute` \| `xml`                                           |
| `dev_mode`            | = `core.debug`   | Proxy auto-generation etc.                                     |
| `proxy_dir`           | system temp      | Generated proxy directory                                      |
| `proxy_namespace`     | —                | Proxy class namespace                                          |
| `native_lazy_objects` | `true` (PHP 8.4+)| Use PHP native lazy objects for proxies (avoids `symfony/var-exporter`) |

> DBAL 4 cannot wrap a pre-existing raw PDO, so Doctrine ORM does **not** support
> layer mode against a plain `pdo` database. To share one connection, point
> `connection` at a `doctrine_dbal` database instead.

```xml
<database name="main" class="doctrine">
  <ae:parameter name="driver">pdo_pgsql</ae:parameter>
  <ae:parameter name="host">localhost</ae:parameter>
  <ae:parameter name="dbname">app</ae:parameter>
  <ae:parameter name="user">app</ae:parameter>
  <ae:parameter name="password">secret</ae:parameter>
  <ae:parameter name="entity_paths">
    <ae:parameter>/srv/app/src/Entity</ae:parameter>
  </ae:parameter>
</database>
```

**Typed accessors:**

```php
/** @var Quiote\Database\Adapter\Doctrine\DoctrineDatabase $db */
$db = $context->getDatabaseManager()->getDatabase('main');

$em   = $db->getEntityManager();           // Doctrine\ORM\EntityManagerInterface
$dbal = $db->getDbalConnection();          // Doctrine\DBAL\Connection
$repo = $db->getRepository(User::class);   // Doctrine\ORM\EntityRepository
```

---

## Doctrine DBAL

`class="doctrine_dbal"` → `DoctrineDbalDatabase`. The connection abstraction and
query builder **without** the ORM/entity layer. Requires `doctrine/dbal` +
`DoctrinePlugin`. `getConnection()` returns the DBAL `Connection`.

| Parameter    | Description                                             |
|--------------|---------------------------------------------------------|
| `connection` | Inline DBAL params array (alternative to flat params)   |
| `url`        | DBAL DSN URL                                            |
| `driver`,`host`,`port`,`dbname`,`user`/`username`,`password`,`path`,`memory`,`charset` | Same as Doctrine ORM |

```xml
<database name="reporting" class="doctrine_dbal">
  <ae:parameter name="driver">pdo_pgsql</ae:parameter>
  <ae:parameter name="host">localhost</ae:parameter>
  <ae:parameter name="dbname">app</ae:parameter>
  <ae:parameter name="user">app</ae:parameter>
  <ae:parameter name="password">secret</ae:parameter>
</database>
```

**Typed accessors:**

```php
/** @var Quiote\Database\Adapter\Doctrine\DoctrineDbalDatabase $db */
$conn = $db->getDbalConnection();          // Doctrine\DBAL\Connection
$qb   = $db->getQueryBuilder();            // Doctrine\DBAL\Query\QueryBuilder
$name = $qb->select('name')->from('users')->where('id = 1')->fetchOne();
```

---

## Cycle ORM

`class="cycle"` → `CycleDatabase` (Cycle ORM v2 — built for long-running
workers). Requires `cycle/orm` + `cycle/database` + `CyclePlugin`.
`getConnection()` returns the `ORMInterface`.

Cycle owns its own driver configuration via **PHP config objects**, so configure
it through a `databases.php` (or a subclass), not XML.

| Parameter         | Description                                                                 |
|-------------------|-----------------------------------------------------------------------------|
| `cycle`           | A native Cycle `DatabaseConfig` array (`default`, `databases`, `connections`). Required. |
| `schema`          | A precompiled Cycle schema array (or `Cycle\ORM\Schema`).                    |
| `schema_provider` | A `callable(self): (Schema\|array)` returning the schema (alternative to `schema`). |

> Schema **compilation** from annotated entities (`cycle/annotated` +
> `cycle/schema-builder`) is an application/console concern — supply a compiled,
> cached schema here rather than recompiling on every boot.

```php
<?php
// Config/databases.php
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig;

return [
    'default'   => 'main',
    'databases' => [
        'main' => [
            'class'      => 'cycle',
            'parameters' => [
                'cycle' => [
                    'default'     => 'default',
                    'databases'   => ['default' => ['connection' => 'pg']],
                    'connections' => [
                        'pg' => new PostgresDriverConfig(
                            connection: new TcpConnectionConfig(
                                database: 'app', host: 'localhost', port: 5432,
                                user: 'app', password: 'secret',
                            ),
                        ),
                    ],
                ],
                'schema' => require __DIR__ . '/cycle-schema.php',
            ],
        ],
    ],
];
```

**Typed accessors:**

```php
/** @var Quiote\Database\Adapter\Cycle\CycleDatabase $db */
$orm  = $db->getOrm();                      // Cycle\ORM\ORMInterface
$repo = $db->getRepository('user');         // Cycle\ORM\RepositoryInterface
$user = $repo->findByPK(1);
```

---

## Layer mode vs standalone mode

ORM adapters resolve their underlying connection two ways:

- **Standalone mode** — the adapter builds its own connection from the parameters
  you give it (`driver`/`host`/… or an inline `connection` array).
- **Layer mode** — set `connection` to the **name** of another configured
  database; the ORM reuses that connection. Credentials live in one place and the
  underlying connection's ping/reconnect is shared.

```xml
<databases default="orm">
  <!-- one place owns the raw connection -->
  <database name="pdo_main" class="pdo">
    <ae:parameter name="dsn">pgsql:host=localhost;dbname=app</ae:parameter>
    <ae:parameter name="username">app</ae:parameter>
    <ae:parameter name="password">secret</ae:parameter>
  </database>

  <!-- Eloquent layers on top of it -->
  <database name="orm" class="eloquent">
    <ae:parameter name="connection">pdo_main</ae:parameter>
    <ae:parameter name="driver">pgsql</ae:parameter>
  </database>
</databases>
```

Support by adapter:

| Adapter        | Layer mode                                                        |
|----------------|------------------------------------------------------------------|
| Eloquent       | ✅ borrows the referenced PDO (`driver` still required)           |
| Doctrine DBAL  | ✅ via inline params (DBAL 4 can't wrap a raw PDO)                |
| Doctrine ORM   | ✅ but only against a `doctrine_dbal` database, not a raw `pdo`   |
| Cycle          | Uses its own `cycle` driver config                                |

---

## Using a connection in application code

```php
// The Database wrapper (lifecycle):
$db = $context->getDatabaseManager()->getDatabase('main');

// The underlying PDO / ORM object (generic):
$conn = $context->getDatabaseConnection('main');
// equivalent to: $context->getDatabaseManager()->getDatabase('main')->getConnection();

// Typed, per adapter — cast the wrapper, then use its accessor:
/** @var Quiote\Database\Adapter\Doctrine\DoctrineDatabase $db */
$em = $db->getEntityManager();
```

Omit the name to use the default connection.

Every adapter also implements `getPdo(): \PDO` for hand-written SQL — see
`docs/DATABASE_PDO_ACCESS.md` for what it returns per adapter (some, like
Cycle, can't expose one and throw with an alternative instead).

---

## Worker mode & connection lifecycle

Quiote runs long-lived worker processes (e.g. FrankenPHP). Connections are opened
lazily and **kept across requests**; per-request state is cleared. Each adapter
implements:

| Hook         | When                        | Behavior                                                            |
|--------------|-----------------------------|---------------------------------------------------------------------|
| `ping()`     | connection recycling        | Runs a lightweight `SELECT 1`; nulls a dead connection so the next use reconnects lazily. |
| `reset()`    | request boundary            | Clears per-request state — Doctrine `EntityManager::clear()`, Cycle `Heap::clean()` — then tears down. |
| `shutdown()` | teardown                    | Rolls back any dangling transaction and closes the connection.      |

Design rule: **expensive, stateless artifacts survive across requests**
(connections, compiled metadata/schema); **per-request mutable state is cleared**
(identity maps, open transactions). You don't call these yourself — the framework
does, via `DatabaseManager::recycleConnections()` and the container's request
reset.

---

## Installing the libraries

The ORM libraries are optional (`suggest`, not `require`). The adapter classes
load fine without them and only fail — with an actionable message — when you
actually connect.

```bash
composer require illuminate/database        # Eloquent
composer require doctrine/orm doctrine/dbal # Doctrine ORM (+ DBAL)
composer require doctrine/dbal              # Doctrine DBAL only
composer require cycle/orm cycle/database   # Cycle
```

**PDO drivers.** You also need the PDO driver for your database compiled into PHP.
The database *servers* are not required locally for tests (see Testcontainers
below); only the client drivers.

| Database   | PDO driver   | Debian/Ubuntu package |
|------------|--------------|-----------------------|
| PostgreSQL | `pdo_pgsql`  | `php8.5-pgsql`        |
| MySQL      | `pdo_mysql`  | `php8.5-mysql`        |
| SQLite     | `pdo_sqlite` | `php8.5-sqlite3`      |

SQL Server (`pdo_sqlsrv`) and Oracle (`pdo_oci`) work with Doctrine/Cycle but
need Microsoft/Oracle client tooling; install only if you target them.

---

## Integration testing with Testcontainers

Real-database integration tests live under `tests/integration/` and are tagged
`#[Group('integration')]`, excluded from the default `composer test` run. They
spin up real MySQL and PostgreSQL via `testcontainers/testcontainers` and run CRUD
round-trips through every adapter.

```bash
composer test:integration
```

Requires Docker. The harness (`tests/lib/database/DatabaseContainers.php`) shares
one container per engine across the run, pre-pulls images via the docker CLI
(so it works with Docker Desktop's `credsStore` on WSL2), prunes orphaned
containers by label, and auto-removes on stop. Tests skip cleanly when Docker or
the relevant PDO driver is unavailable.

---

## Writing a custom adapter

Extend `Quiote\Database\AbstractOrmDatabase`, build your ORM into
`$this->connection` in `connect()`, and expose typed accessors:

```php
use Quiote\Database\AbstractOrmDatabase;

class MyOrmDatabase extends AbstractOrmDatabase
{
    protected function connect()
    {
        $this->requireLibrary(\My\Orm::class, 'vendor/my-orm');

        // Layer mode (reuse another database) or standalone (build your own):
        $pdo = $this->resolveUnderlyingPdo();      // if referencing another db
        // or: $params = $this->resolveUnderlyingConnection();

        $this->connection = new \My\Orm($pdo /* ... */);
    }

    public function getMyOrm(): \My\Orm { return $this->getConnection(); }

    #[\Override] public function ping(): bool { /* SELECT 1, null on failure */ }
}
```

Ship it as a plugin to get a short alias:

```php
use Quiote\Plugin\{PluginInterface, PluginRegistrar};

final class MyOrmPlugin implements PluginInterface
{
    public function name(): string { return 'vendor/my-orm'; }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->databaseDriver('myorm', MyOrmDatabase::class);
    }
}
```

`AbstractOrmDatabase` gives you `resolveUnderlyingConnection()` /
`resolveUnderlyingPdo()` (layer/standalone resolution), `requireLibrary()` (a
friendly "install this package" guard), and a default worker-safe `shutdown()`.

---

## Reference

**Adapter classes** (namespace `Quiote\Database`):

| Alias           | Class                                         | Plugin           |
|-----------------|-----------------------------------------------|------------------|
| `pdo`           | `PdoDatabase`                                 | — (built in)     |
| `eloquent`      | `Adapter\Eloquent\EloquentDatabase`           | `Adapter\Eloquent\EloquentPlugin` |
| `doctrine`      | `Adapter\Doctrine\DoctrineDatabase`           | `Adapter\Doctrine\DoctrinePlugin` |
| `doctrine_dbal` | `Adapter\Doctrine\DoctrineDbalDatabase`       | `Adapter\Doctrine\DoctrinePlugin` |
| `cycle`         | `Adapter\Cycle\CycleDatabase`                 | `Adapter\Cycle\CyclePlugin`       |

**Key types:** `Quiote\Database\Database` (base wrapper),
`Quiote\Database\AbstractOrmDatabase` (ORM base),
`Quiote\Database\DatabaseManager` (registry/factory),
`Quiote\Database\DatabaseDriverRegistry` (alias → class),
`Quiote\Plugin\PluginRegistrar::databaseDriver()` (alias contribution seam).
