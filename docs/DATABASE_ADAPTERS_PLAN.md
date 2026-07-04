# Database Adapters Plan — First-class ORM layers behind `DatabaseManager`

Status: **partially implemented** (foundation + Eloquent/Doctrine/Cycle adapters landed in-tree; Propel/Propulsion tracked separately)
Author: (drafted with Claude)

> **User-facing usage docs:** `docs/DATABASE.md` (configuration, per-adapter parameters,
> examples, worker lifecycle, custom adapters) — this file is design/status only.

## Implementation status (2026-07-03)

Landed in-tree (all under `Quiote\Database\`), 1811-test suite green:
- **Foundation:** `DatabaseDriverRegistry` (alias → adapter FQCN; `pdo` built in),
  `AbstractOrmDatabase` (layer/standalone connection resolution + `requireLibrary()` guard +
  worker `shutdown()`), `DatabaseConfigHandler` resolves aliases at compile time,
  `PluginRegistrar::databaseDriver()` seam (+ `PluginManager::reset()` clears the registry).
- **Adapters** (`Quiote\Database\Adapter\{Eloquent,Doctrine,Cycle}\`): `EloquentDatabase`,
  `DoctrineDatabase` (ORM 3), `DoctrineDbalDatabase` (Tier 2), `CycleDatabase`, each with typed
  accessors and worker `ping()`/`reset()`/`shutdown()`. Each ships an opt-in `*Plugin` that
  registers its alias (`eloquent`, `doctrine`, `doctrine_dbal`, `cycle`).
- ORM libs are `suggest`-only; adapter classes load fine without them and fail with an actionable
  message at `connect()`. Tests: registry, `AbstractOrmDatabase` resolution, alias resolution, and
  a guarded Eloquent round-trip (skips unless `illuminate/database` is installed).

**Integration tested against real databases (Testcontainers).** ORMs are now `require-dev`
(doctrine/orm ^3.6, doctrine/dbal ^4.4, illuminate/database ^13.18, cycle/orm ^2.18,
cycle/database ^2.21) + `testcontainers/testcontainers ^1.0`. `composer test:integration` spins up
real Postgres (and MySQL where `pdo_mysql` is present) via Testcontainers and runs CRUD round-trips
through every adapter: DBAL query builder, Eloquent query builder + layer-mode PDO borrowing,
Doctrine entity CRUD + identity-map clear, Cycle entity CRUD + heap clean. Tagged
`#[Group('integration')]`, excluded from the default run (like `e2e`/`apcu`); harness in
`tests/lib/database/DatabaseContainers.php` (shared lazy containers, orphan-pruning by label,
auto-remove). Notes: Doctrine ORM 3 needs a lazy-proxy backend — the adapter enables PHP 8.4+
native lazy objects by default (`native_lazy_objects` param) to avoid a symfony/var-exporter dep.

Not yet done: PSR-6 cache bridging for Doctrine/Cycle, migration/codegen console commands (§8),
package extraction (§7.2). Legacy `Doctrine2*` adapters not yet removed.
Propel → see `docs/PROPULSION_WORKER_REWORK.md`.

Related: `Quiote/Database/*`, `Quiote/Config/DatabaseConfigHandler.php`, `Quiote/Plugin/*`, `docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md`

## 1. Goal

Ship first-class, "batteries-configured" adapters for the ORM layers people actually
use in 2026, so that:

```php
$em   = $context->getDatabaseManager()->getDatabase('main')->getConnection(); // Doctrine EntityManager
$orm  = $context->getDatabaseManager()->getDatabase('catalog')->getConnection(); // Cycle ORM
User::where('active', true)->get();                                            // Eloquent, globally booted
```

The app author declares a connection in `databases.xml`, and Quiote instantiates and
wires up the chosen ORM — connection, credentials, event manager, caches, and
worker-mode lifecycle — with no ORM bootstrapping code in the app.

## 2. Decisions (some deferred to us by the request)

1. **PDO is the only raw driver in core.** We drop the idea of native driver adapters
   (mysqli/pgsql/oci8 as our own classes). Every ORM either layers on a `PdoDatabase`
   connection or builds its own PDO from the same params. Native drivers remain reachable
   *through* an ORM that supports them (e.g. Doctrine DBAL's `pdo_mysql` vs `mysqli`), not
   as Quiote adapters. This matches reality: Eloquent, Doctrine DBAL, and Cycle all default
   to PDO. Confirmed: correct call.

2. **`getDatabase()` keeps returning the `Database` wrapper; `getConnection()` returns the
   ORM object.** This preserves the lifecycle contract (`startup`/`shutdown`/`ping`/`reset`/
   worker recycling) that a raw ORM handle can't provide. We do **not** make `getDatabase()`
   return the EntityManager/Capsule directly — that would break `recycleConnections()` and
   the `ResetInterface` worker story. Instead we add typed convenience accessors (§6) so the
   ergonomics are close to what you asked for without losing the wrapper.

3. **Adapters ship as separate composer packages that self-register as Quiote plugins**,
   not in core. Core stays ORM-free (no ORM in `require`; PDO stays a `suggest`). See §7.

4. **The stale Doctrine in-tree adapters** (`DoctrineDatabase` = Doctrine 1,
   `Doctrine2Database`, `Doctrine2dbalDatabase`, `Doctrine2ormDatabase`) are **legacy**. They
   move to a `quioteframework/quiote-legacy-db` package (or are deleted) once the modern
   Doctrine adapter lands. Do not extend them.

5. **Propel is NOT legacy in this ecosystem — it's a flagship.** We own a PHP 8.5-targeted,
   namespaced fork of Propel 1.6 (currently `../jakamo/3rdparty/propel`, autoloaded under
   `Propel\`) to be released as **`quioteframework/propulsion`**. The existing
   `Quiote/Database/PropelDatabase.php` is already wired to it and already worker-aware — it's
   the *reference* adapter, not something to delete. See §3 Tier 1 and §4.3.

## 3. Which ORMs — tiers

Based on 2026 landscape (Doctrine = the serious default, Eloquent = biggest mindshare,
Cycle = the long-running/worker specialist, Propel = effectively abandoned):

### Tier 1 — flagship, first-class, maintained by us
| ORM | Package we ship | Underlying dep | Why |
|-----|-----------------|----------------|-----|
| **Eloquent** | `quioteframework/quiote-eloquent` | `illuminate/database` | Highest demand; standalone via Capsule Manager; bundles query builder + schema builder + migrations in one dep. |
| **Doctrine ORM 3 / DBAL 4** | `quioteframework/quiote-doctrine` | `doctrine/orm`, `doctrine/dbal` | The de-facto "serious" data-mapper ORM. Modern replacement for the stale in-tree adapters. |
| **Cycle ORM v2** | `quioteframework/quiote-cycle` | `cycle/orm` | Built for long-running processes (Spiral/RoadRunner) — a natural fit for Quiote's worker mode and a real differentiator. |
| **Propel (our fork)** | `quiote-propel` adapter (in core today) | **`quioteframework/propulsion`** | We own the ORM (PHP 8.5 fork of Propel 1.6, namespaced `Propel\`). Full vertical control: ORM + adapter + build tooling. Already the ORM the Jakamo app runs on. |

### Tier 2 — query-builder / DBAL only (no entities)
| Layer | Package | Dep | Why |
|-------|---------|-----|-----|
| **Doctrine DBAL** (connection + query builder, no ORM) | part of `quiote-doctrine` | `doctrine/dbal` | For people who want connection abstraction + a query builder without the entity layer. |
| **Eloquent query builder** | part of `quiote-eloquent` | `illuminate/database` | Comes free with the Eloquent dep; expose `->table()` without requiring models. |

### Tier 3 — community, best-effort
- **RedBeanPHP** (zero-config), **Atlas ORM** (data-mapper on DBAL) — document the seam and accept community adapters; don't ship first-class.

> Note: upstream Propel (propelorm.org) is effectively abandoned, which is *why* we forked it.
> Our fork (`quioteframework/propulsion`) is Tier 1, not Tier 3 — see above.

## 4. Architecture — the adapter contract

Nothing about `DatabaseManager` or the `Database` base class needs to change to *build* an
ORM adapter — `Doctrine2ormDatabase` already proves an adapter can put a non-PDO object in
`$connection`. What we add is a thin shared base plus a worker-safe lifecycle, so each
concrete adapter is small.

### 4.1 New shared base: `AbstractOrmDatabase extends Database`

Responsibilities pulled up so each ORM class is thin:

- **Underlying-connection resolution** — two config modes:
  - *Layer mode:* `connection: pdo_main` → reuse another `<database>`'s PDO
    (`$this->getDatabaseManager()->getDatabase('pdo_main')->getConnection()`), exactly the
    pattern `Doctrine2ormDatabase` uses today. Credentials live in one place; PDO-level
    `ping()`/reconnect is reused.
  - *Standalone mode:* `dsn`/`username`/`password`/`options` → the adapter builds its own
    PDO/driver. Needed when the ORM wants to own reconnection (Eloquent) or driver features
    (DBAL native drivers).
  - Helper: `protected function resolveUnderlyingPdo(): \PDO` and
    `protected function connectionParams(): array` (dsn parsed into the driver-specific shape).
- **Worker-mode lifecycle hooks** (see §5) with sane ORM defaults.
- **Typed accessor scaffolding** for §6.

### 4.2 Concrete adapters (sketch)

`EloquentDatabase extends AbstractOrmDatabase`
- `connect()`: builds `Illuminate\Database\Capsule\Manager`, `addConnection($params, $name)`,
  optionally `setAsGlobal()` + `bootEloquent()` (gated by a `global`/`boot_eloquent` param),
  wires an event dispatcher (reuse the framework's PSR-14 dispatcher via a small bridge).
  Store the `Manager` in `$this->connection`.
- Typed getters: `getCapsule(): Manager`, `getConnection(): Manager`,
  `getQueryBuilder(?string $table)`, `getSchemaBuilder()`.
- Worker note: **do not** `setAsGlobal()` per request in worker mode — boot the static facade
  once at `startup()`, and on `reset()` only `reconnect()` the underlying connection. Guard
  the static Capsule against double-registration across contexts.

`DoctrineDatabase extends AbstractOrmDatabase` (modern; supersedes the `Doctrine2*` classes)
- `connect()`: build `Doctrine\DBAL\Connection` (from resolved PDO or params), then
  `Doctrine\ORM\EntityManager` with `ORMSetup::createAttributeMetadataConfiguration(...)`
  driven by params (`entity_paths`, `proxy_dir`, `dev_mode`, `metadata_cache`, `naming_strategy`).
  Bridge Quiote's PSR-6/PSR-16 cache into Doctrine's metadata/query caches.
- Two entry points: `class="...\DoctrineDatabase"` → EntityManager;
  `class="...\DoctrineDbalDatabase"` → bare `DBAL\Connection` + query builder (Tier 2).
- Typed getters: `getEntityManager()`, `getDbalConnection()`, `getRepository(string $entity)`.

`CycleDatabase extends AbstractOrmDatabase`
- `connect()`: build `Cycle\Database\DatabaseManager` (Cycle's own) from a driver config
  derived from our params, a `Cycle\ORM\Schema` (compiled from annotated entities, cached via
  our cache), and `Cycle\ORM\ORM`. Store the `ORM` in `$this->connection`.
- Worker note: Cycle is designed for this — on `reset()` clean the ORM heap
  (`$orm->getHeap()->clean()`) and roll back any dangling transaction; keep the compiled
  schema across requests (that's the whole point of Cycle in long-running mode).
- Typed getters: `getOrm()`, `getCycleDatabaseManager()`, `getRepository(string $entity)`.

`PropelDatabase` (exists today — the reference adapter; underlying dep `quioteframework/propulsion`)
- Propel 1.6 is a **static god-class** model: `Propel::init($configFile)` boots a process-global
  registry; `Propel::getConnection($datasource)` returns a `PropelPDO`; generated OM/Peer/Query
  classes talk to it. There is no per-connection object graph like Doctrine/Cycle — the adapter's
  job is to bridge `databases.xml` params into Propel's `require`d array config and expose the
  connection. The current adapter already does this (`config`, `datasource`, `use_as_default`,
  `overrides`, `init_queries`, `enable_instance_pooling`).
- Does **not** fit the `AbstractOrmDatabase` layer/standalone-PDO model cleanly, because Propel
  owns its own connection factory (`initConnection`) and config file. Keep it as a direct
  `Database` subclass; do not force it under `AbstractOrmDatabase`. `AbstractOrmDatabase` is for
  the "wrap a resolved PDO in an EntityManager/ORM" adapters (Eloquent/Doctrine/Cycle).
- **Code-generation build step**: Propel needs `propel-gen`-style OM generation from
  `schema.xml`. This is a first-class opportunity for a contributed console command
  (`db:propel:build` / `db:propel:model`) shipped by the adapter (see §8).
- Typed getters: `getConnection(): PropelPDO`, plus a static-facade note (models call
  `Propel::getConnection()` directly, so the adapter's main duty is init + lifecycle, not handing
  out the connection).
- Work still needed on the fork (the "needs some work"): PHP 8.5 compatibility pass (it targets
  `>=8.4.0` today), rename/rebrand to `quioteframework/propulsion` (`Propel\` namespace can stay),
  and a test run against PHP 8.5. Track separately from the adapter work.
- **Deeper: de-god-classing for worker safety.** The user is committed to reworking Propulsion's
  internals away from the static god-class model so it behaves correctly in long-lived workers.
  That is a substantial project with its own design doc — see
  **`docs/PROPULSION_WORKER_REWORK.md`**. Summary: split the static state into a process-scoped
  `ServiceContainer` (connections/adapters/maps — survive requests) and a request-scoped `Session`
  (instance pools + open transactions + master-pin — reset per request), reached through a swappable
  holder so the `Propel::` facade and generated-code API stay unchanged. Once that lands, this
  adapter's `reset()` swaps/clears the Session instead of relying on `enable_instance_pooling=false`.

## 5. Worker mode — the hard part (highest risk)

Quiote runs long-lived workers (FrankenPHP; `DatabaseManager::recycleConnections()`,
`Database::reset()` / `ResetInterface`, `ping()`). Stateful ORMs hold identity maps, unit-of-work
state, and connection handles that **must not** leak between requests. Each Tier-1 adapter must
implement:

| Hook | Eloquent | Doctrine | Cycle |
|------|----------|----------|-------|
| `ping()` (recycle) | `getConnection()->reconnect()` on failure | `$conn->executeQuery('SELECT 1')`; reconnect via DBAL | driver `SELECT 1`; reconnect |
| `reset()` (per-request boundary) | clear pending transactions; **do not** rebuild Capsule | `$em->clear()`; rollback dangling tx; keep metadata | `$orm->getHeap()->clean()`; new transaction scope; keep schema |
| `shutdown()` | close PDO, null Manager | `$em->close()`; null | close driver; null ORM/keep schema cache |
| `startup()` | boot global facade **once** | warm metadata cache | compile+cache schema once |

**Propel (special case — process-global static state).** Propel 1.6's **instance pooling** is a
static identity map keyed by class+PK that lives for the whole worker process — the single biggest
cross-request leak risk here, and worse than Doctrine/Cycle because it's global, not per-EM. Rules:
- `ping()` — already implemented (`SELECT 1`, null the handle on failure for lazy reconnect). Keep.
- `reset()` — clear the instance pool every request (`<Peer>::clearInstancePool()` / a global
  `Propel::clearAllInstancePools()` helper on the fork) and roll back any open transaction. This
  is **mandatory**; without it, request A's objects are served to request B. Consider defaulting
  `enable_instance_pooling` to `false` in worker mode unless the app opts in.
- `shutdown()` — `Propel::close()` (the manager already calls this globally). Keep `init()` results
  (config registry) across requests; only per-request pooled instances are cleared.
- Add `Propel::clearAllInstancePools()` to the `propulsion` fork if it doesn't exist — it's the
  clean seam for the reset hook.

Design rule: **expensive, stateless artifacts (compiled schema, metadata, proxies, Propel's config
registry) survive across requests; per-request mutable state (identity map / instance pool / unit of
work / open transactions) is cleared in `reset()`.** Wire `reset()` into the existing request-boundary reset the container already
performs, and make `recycleConnections()` call `ping()` per §1 of the existing manager. This
section deserves a dedicated test matrix (see §9).

## 6. API ergonomics — getting close to "return a Laravel whatsit"

Keep `getDatabase()->getConnection()` as the generic path (returns `mixed` — the ORM object),
but add typed sugar so callers get IDE completion:

- On `Context`: `getEntityManager(?string $name = null)`, `getEloquent(?string $name = null)`,
  `getCycleOrm(?string $name = null)` — thin, each asserts the wrapper is the expected adapter
  type and returns the typed connection, else throws a clear `DatabaseException`.
  (Alternatively a single generic `getOrm(string $name, class-string $expected)`.)
- Each adapter exposes its own typed getters (`getEntityManager()`, `getCapsule()`, `getOrm()`)
  as listed in §4.2.
- Document the pattern in `docs/CONFIGURATION_SETTINGS.md` + a new `docs/DATABASE.md`.

Do **not** overload `getDatabase()`'s return type per driver — that defeats static analysis and
the wrapper lifecycle. This is the one place the request's literal phrasing loses to correctness.

## 7. Packaging & the plugin seam (the real gap)

The plugin registrar today has `service()`, `configDefault()`, `command()`, middleware, events,
modules, HTTP clients — but **no seam for a database adapter or a `databases.xml`/factory
contribution**. Two things to add:

### 7.1 Driver aliases (small, high-value)
Let config reference short driver names instead of FQCNs:
```xml
<database name="main" class="eloquent"> ... </database>
```
- Add a driver-alias map resolved in `DatabaseConfigHandler::executeArray()` (map short →
  FQCN before emitting `new <class>()`). Core seeds `pdo` → `Quiote\Database\PdoDatabase`.
- New registrar seam `PluginRegistrar::databaseDriver(string $alias, string $adapterClass)`
  → `PluginManager::addDatabaseDriver()`, applied set-if-absent (matches existing plugin
  contribution semantics). Each adapter package registers its own alias (`eloquent`,
  `doctrine`, `doctrine-dbal`, `cycle`).
- Requires the config-cache to know about plugin-contributed aliases at compile time. Since
  `PluginManager::bootFromConfig()` runs in `Quiote::bootstrap()` **before** contexts/config
  handlers, the alias map is available when `DatabaseConfigHandler` compiles. Verify ordering
  and add the alias map to the config-cache key so a plugin change busts the cache.

### 7.2 Adapter package layout
Each package (`quiote-eloquent`, `quiote-doctrine`, `quiote-cycle`):
- `require`: `quioteframework/quiote` + the ORM (`illuminate/database` / `doctrine/orm` / `cycle/orm`).
- Ships the `Database` subclass + a `PluginInterface` implementation whose `register()`:
  - `->databaseDriver('eloquent', EloquentDatabase::class)`
  - `->configDefault(...)` for sane ORM defaults
  - `->command(...)` for migration/schema console commands (§8)
- App enables it by adding the plugin class to the `plugins` config key — then just uses
  `class="eloquent"` in `databases.xml`.

## 8. Migrations & schema (phase 2, big value-add — currently a total gap)

No migration/schema system exists in Quiote. Rather than build one, expose each ORM's tool as
contributed `bin/quiote` console commands from the adapter plugin:
- Eloquent → wrap `illuminate/database` migrator + schema builder (`db:migrate`, `db:make-migration`).
- Doctrine → `doctrine/migrations` (standalone-friendly) → `db:migrate`, `db:diff`.
- Cycle → `cycle/migrations` + `cycle/schema-migrations` → `db:migrate`, `db:sync`.
- Propel → the generator's OM/SQL build (`propel-gen` equivalent) → `db:propel:build`,
  `db:propel:sql`, `db:propel:model`; migrations via Propel's own diff tooling if we revive it.
Normalize command names (`db:migrate`, `db:rollback`, `db:status`) across adapters so the app
UX is consistent regardless of ORM. This is optional and lands after Tier-1 adapters are stable.

## 9. Testing

- **Adapter unit tests** per package: connect in both layer mode and standalone mode; assert
  `getConnection()` returns the right ORM type; typed getters work.
- **Worker-mode matrix** (highest priority): simulate N sequential requests through one worker;
  assert no identity-map/state bleed, connections survive `ping()`, `reset()` clears per-request
  state, schema/metadata caches persist. One test class per ORM.
- **Config-cache tests**: driver aliases resolve; plugin-contributed aliases bust the cache;
  APCu path (`test:apcu`) still compiles adapter configs.
- **Integration** against real DBs (sqlite in-memory always; MySQL/Postgres in the docker e2e
  group) for each Tier-1 adapter doing a real CRUD round-trip.

## 10. Rollout order

1. `AbstractOrmDatabase` + worker-lifecycle hooks in core; driver-alias resolution in
   `DatabaseConfigHandler`; `databaseDriver()` registrar seam. (Core PR — small.)
2. `quiote-doctrine` (ORM + DBAL) — replaces the stale in-tree Doctrine adapters; establishes
   the package template.
3. `quiote-eloquent` — highest demand; validates the "boot a global static facade in a worker"
   edge cases.
4. `quiote-cycle` — showcases the worker-mode fit.
5. **Propel / `propulsion`** (can run in parallel — different owner/skills): finish the PHP 8.5
   pass on the fork, rebrand to `quioteframework/propulsion`, add `clearAllInstancePools()`, wire
   the existing `PropelDatabase` adapter's `reset()` to it, and register the `propel` driver alias.
   The adapter already exists, so this is mostly fork-hardening + the worker-reset hook.
6. Legacy sweep: move/delete old Doctrine 1/2 adapters into `quiote-legacy-db`. (Propel stays.)
7. Phase 2: migration/schema/codegen console commands per adapter.

## 11. Open questions

- Do we bridge Quiote's PSR-14 event dispatcher into Eloquent/Doctrine event systems, or keep
  ORM events isolated? (Leaning: optional bridge, off by default.)
- Cache bridging: reuse the framework's PSR-6/PSR-16 cache for Doctrine metadata / Cycle schema,
  or let each ORM own its cache? (Leaning: reuse ours so worker warmup is one system.)
- Should `class="eloquent"` short aliases be allowed to *shadow* a full FQCN, and what wins on
  collision? (Leaning: app config FQCN always wins; plugin aliases are set-if-absent.)
- One EntityManager per named connection vs. a shared connection with multiple EMs — expose both?
