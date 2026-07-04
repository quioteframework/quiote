# Propulsion — de-god-classing Propel for worker safety

Status: proposal / planning
Scope: the `quioteframework/propulsion` fork (Propel 1.6, namespaced `Propel\`, currently at
`../jakamo/3rdparty/propel`). NOT the Quiote adapter — that's `docs/DATABASE_ADAPTERS_PLAN.md` §4.3.
Goal: make Propel behave correctly and efficiently in long-lived worker processes (FrankenPHP),
by moving runtime state off process-global statics into a swappable, resettable context — without
breaking the ergonomic `BookQuery::create()->findPk()` / `BookPeer::doSelect()` API or forcing app
rewrites.

> This doc should migrate into the `propulsion` repo once it exists. It lives here for now because
> the fork isn't split out yet.

## 1. The problem, precisely

Every piece of Propel's runtime state is `private static` on the single `Propel` class
(`runtime/Lib/Propel.php`):

| Static | Lifetime it *should* have | Lifetime it has now |
|--------|---------------------------|---------------------|
| `$connectionMap` (PDO handles, master/slave) | **process** (survive requests, reconnect on death) | process, but no per-request tx cleanup |
| `$adapterMap` (DBAdapter per datasource) | process | process ✓ |
| `$dbMaps` (Database/Table/ColumnMap) | process | process ✓ |
| `$configuration` | process | process ✓ |
| `$defaultDBName` | process | process ✓ |
| `$instancePoolingEnabled` | process config | process ✓ |
| `$forceMasterConnection` | **request** (a request may pin to master) | process — **leaks across requests** |
| Generated `<Peer>::$instances` (instance pool) | **request** (identity map for one request) | **process — leaks objects across requests** |
| open transaction on a `PropelPDO` | **request** (must not survive a crashed request) | process — **leaks across requests** |

The three **request-scoped** rows that currently live at process scope are the worker bugs:
1. **Instance pools** — request B is served request A's hydrated objects from the static pool.
2. **Dangling transactions** — a request that dies mid-transaction leaves the next request on that
   connection inside an open (or uncommittable) transaction.
3. **`forceMasterConnection`** — a request that pins to master leaks that pin to later requests.

Everything else (connections, adapters, maps, config) is *correctly* process-scoped and should be
**kept** across requests for performance — reconnecting per request is the wrong fix.

## 2. Target architecture — two scopes behind a swappable holder

Split the god-class state into two containers, reached through one indirection point. Keep the
`Propel` facade as the ergonomic, backward-compatible entry point — it becomes a thin delegator.

```
Propel  (static facade — unchanged public API)
  ├─ getServiceContainer(): ServiceContainer   ── PROCESS scope (one per worker)
  │     ├─ connectionManager (PDO handles, master/slave, ping/reconnect)
  │     ├─ adapterMap
  │     ├─ databaseMaps
  │     ├─ configuration, defaultDatasource
  │     ├─ instancePoolingEnabled (config)
  │     └─ logger / profiler
  └─ getSession(): Session                      ── REQUEST scope (reset per request)
        ├─ instancePools   (moved off <Peer>::$instances)
        ├─ forceMasterConnection
        └─ per-request profiler counters
```

- **`ServiceContainer`** is Propel 2's proven design (a swappable object holding connections +
  adapters + maps). One per worker process; connections persist. This is where `forceReconnect()`
  / `isConnectionDropped()` (already in the fork) belong.
- **`Session`** is new: it owns the per-request mutable state, primarily the instance pools.
- **The holder** — `Propel::getSession()` / `getServiceContainer()` resolve through a single
  swappable holder. Today: a static pointer swapped per request. Later: a `\Fiber`-local holder for
  concurrency (see §6). Generated code keeps calling `Propel::getConnection()` /
  `Propel::getSession()`; only what those *return* becomes swappable.

### Why this preserves the ergonomic API
App code (`BookQuery::create()->findPk(1)`, `BookPeer::doSelect($c, $con)`) and generated code call
static entry points. We keep those. We only change *where the state lives*. No `$context` parameter
threading, no constructor injection into generated classes.

## 3. The key move — instance pools become session-owned, signatures unchanged

Instance pools are generated as `static $instances` arrays on each Peer, via the
`PHP84PeerBuilder` template, with a fixed method surface that **everything** funnels through:
`addInstanceToPool`, `getInstanceFromPool`, `removeInstanceFromPool`, `clearInstancePool`,
`clearRelatedInstancePool`. Object hydration (`PHP84ObjectBuilder`) and query find/findPk
(`PHP84QueryBuilder`) and all behaviors (NestedSet, Sortable, SoftDelete) call these methods.

**Design constraint that shrinks the whole project: keep those method signatures identical; change
only their bodies to delegate to the session.** Emitted body becomes, in effect:

```php
public static function getInstanceFromPool($key) {
    return \Propel\Propel::getSession()->getPooled(static::TABLE_NAME, $key);
}
public static function clearInstancePool() {
    \Propel\Propel::getSession()->clearPool(static::TABLE_NAME);
}
```

Consequences:
- Behaviors, hand-written model code, and app code that call `BookPeer::clearInstancePool()` etc.
  **need zero changes** — same API, different backing store.
- `reset()` becomes O(1): drop/replace the `Session` and every table's pool is gone at once — no
  hunting down each Peer's static array.
- Only three template files change (`PHP84PeerBuilder`, and the pool-call sites are already routed
  through the Peer methods so `PHP84ObjectBuilder`/`PHP84QueryBuilder` may need no change at all —
  verify). Legacy `PHP5*` builders are dropped, not ported.
- Models must be **regenerated** with the new templates (owned build step; acceptable).

## 4. Transaction safety on reset (the other must-fix)

`PropelPDO` already tracks `$nestedTransactionCount` / `isInTransaction()` / has a force-rollback
path. Wire it to the request boundary: on reset, for every connection in the ServiceContainer,
if `isInTransaction()` then force `rollBack()` down to depth 0 and reset the nesting counter, so a
crashed request's half-open transaction never bleeds into the next request. This is mandatory and
cheap given the machinery already exists.

## 5. Reset semantics — what survives, what dies

Codifies the design rule from the adapters plan, Propel-specifically:

**Survives across requests (process scope):** connections (with ping/reconnect), adapters, database
/ table / column maps, configuration, compiled metadata, logger. Reconnecting or rebuilding these
per request is the wrong move — it's the perf reason to run workers at all.

**Reset every request (drop/replace the Session):** instance pools, `forceMasterConnection`, any
open transaction (rolled back per §4), per-request profiler counters.

Reset is driven from the Quiote side by `PropelDatabase::reset()` (`ResetInterface`) at the request
boundary the container already runs; see adapters plan §5.

## 6. Concurrency / async (future-proofing, not required now)

If Propulsion ever runs under fibers/Swoole/Amp, a single static "current session" pointer is fatal
(concurrent requests share it). The holder in §2 is the one seam that makes this a later drop-in:
resolve the current `Session` from a `\Fiber`-aware holder (fiber-local, with a fallback to a root
session for non-fiber contexts) instead of a plain static. Do **not** build this now — just make
`Propel::getSession()` the *only* place session lookup happens, so switching the holder
implementation later touches one method, not the generated code.

## 7. Phasing (ship worker-safety incrementally)

**Phase 0 — Characterize.** Write the worker test matrix (§8) as red tests against the current fork.
Pin target PHP (8.5). Decide instance-pooling default in worker mode (proposal: on, because reset
now makes it safe and it's a real intra-request win).

**Phase 1 — Scopes + safety, no generator changes yet (fastest path to a safe app).**
Introduce `ServiceContainer` + `Session`; make `Propel` delegate. Add a *pool registry* so all
static Peer pools can be cleared en masse on reset (interim, until Phase 2 moves them). Wire
transaction-rollback-on-reset (§4) and move `forceMasterConnection` into the Session. This alone
closes the three worker leaks and unblocks the Jakamo app quickly.

**Phase 2 — Move pools into the Session (the real fix).**
Rework `PHP84PeerBuilder` pool method bodies to delegate to `Session` (§3); regenerate models; drop
the Phase 1 pool-registry hack. Now pools are genuinely request-scoped, not process statics.

**Phase 3 — Cleanup + optional concurrency.** Delete legacy `PHP5*` builders and any dead static
paths. Optionally implement the fiber-local holder (§6).

**Phase 4 — Quiote adapter integration.** `PropelDatabase`: hold the ServiceContainer (process),
reset the Session per request, roll back dangling tx. Two-layer lifecycle mapped onto
`DatabaseManager::recycleConnections()` (ping, process scope) and `Database::reset()` (session,
request scope). Register the `propel` driver alias (adapters plan §7.1).

## 8. Worker test matrix (write first, in the propulsion repo)

- **No object bleed:** request A pools Book#1; request B (after reset) must NOT get Book#1 from the
  pool — it re-hydrates from the DB.
- **Transaction cleanup:** simulate a request that `beginTransaction()` then dies; assert the next
  request starts with `isInTransaction() === false` (forced rollback happened).
- **Connection persistence:** across N requests the same PDO handle is reused (no reconnect storm);
  a killed connection is transparently reconnected via `ping()`/`forceReconnect()`.
- **forceMaster isolation:** a request that pins to master does not affect the next request's
  read/slave routing.
- **Memory:** pools do not grow unbounded across N requests — reset actually frees hydrated objects
  (assert with `memory_get_usage` trend / pool counts).
- **Pooling on vs off:** both modes correct after reset.
- **(Phase 3) fiber isolation:** two concurrent fibers see independent pools/sessions.

## 9. Risks / open questions

- **Regeneration burden:** every model in every app on Propulsion must be regenerated for Phase 2.
  Fine for the Jakamo app (owned); document it as a hard requirement of the version bump.
- **Behaviors:** verify all bundled behaviors (NestedSet/Sortable/SoftDelete) only touch pools via
  the fixed Peer method surface — if any reach a static array directly, they need the same delegation
  treatment.
- **`clearRelatedInstancePool`** does cross-table cascades — ensure the session model handles
  cross-table clears (it does naturally, since one session owns all pools).
- **Static `Propel::` calls inside deep hydration** — confirm `getSession()` is cheap (it's a
  holder lookup); if hot, cache the session reference per query execution rather than per-row.
- **Persistent PDO (`ATTR_PERSISTENT`)** + workers + transactions is a footgun — discourage in docs.
- Keep `Propel::init($configFile)` and the `require`d config-array format working (the Quiote
  adapter and existing configs depend on it), or provide a shim.
