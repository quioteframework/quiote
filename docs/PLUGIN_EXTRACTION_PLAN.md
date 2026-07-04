# Plugin Extraction Plan — slimming quiote-core into an unopinionated kernel + opinionated drop-ins

## Status — in progress

This plan takes the framework's stated philosophy ("unopinionated core + opinionated
drop-ins", `docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md`) to its conclusion: move opinionated,
optional, or heavy-dependency subsystems out of `Quiote/` into separately-installable
composer packages under `quioteframework/*`, so a minimal app pulls only the kernel and
opts into the rest with `composer require quioteframework/csrf` (etc.).

**Progress:** §2's core seam gaps (2.1–2.4) are closed. **All 9 Tier-1 packages are now
physically split** into `packages/*` (see docs/MONOREPO_SPLIT_PLAN.md): `mcp`, `ratelimit`,
`csrf`, `whoops`, `db-eloquent`, `db-doctrine`, `db-cycle`, `telemetry-otel`,
`telemetry-dashboard` — none pushed to a standalone repo yet (that's
`docs/MONOREPO_SPLIT_PLAN.md`'s still-open step 3, no `split.yml` exists). Tier 2 (PHPTAL/XSLT
renderers, Gettext, RBAC, YAML config driver, PDO session backends) is the only thing left
in `Quiote/` from the original candidate catalog, unblocked but not yet moved.

The good news from the audit: **the seams already exist and most candidates are already
plugin-shaped.** The MCP subsystem and all three ORM adapters are self-contained plugins
today (their own docblocks anticipate the move). The work splits into three buckets:

1. **Close a handful of core seam gaps** that currently make clean extraction impossible
   (the critical path — do this first).
2. **Relocate** each subsystem into its own package + move its dependency out of core.
3. **Set up the distribution model** (how packages are developed, split, and versioned).

---

## 1. What the plugin system already gives us

`Quiote\Plugin\{PluginInterface, PluginRegistrar, PluginManager}` (see
`docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md`). A plugin's `register(PluginRegistrar $r)` runs
once, during `Quiote::bootstrap()` after settings load (`PluginManager::bootFromConfig()`),
in deterministic order, driven by the `plugins` config key. Contribution seams:

| Seam | Method | Applied |
|---|---|---|
| Config defaults | `configDefault()` | immediately, set-if-absent |
| DI services | `service()` | per-context container (`Context::configureContainer`) |
| Middleware | `middleware()` / `attributedMiddleware()` | `MiddlewareCatalog` |
| Events | `listen()` | `Events` |
| Module dirs (routes) | `moduleDirectory()` | `AttributeRouteScanner` |
| Console commands | `command()` | `Console\Application` |
| DB driver aliases | `databaseDriver()` | `DatabaseDriverRegistry` |
| Named HTTP clients | `httpClient()` | `HttpClientFactory` |

MCP tools/resources/prompts are **not** in this table — `mcpTool()`/`mcpResource()`/`mcpPrompt()`
were removed from core `PluginRegistrar` (§2.1, done): a plugin that wants to contribute to
`Quiote\Mcp\McpCatalog` calls it directly instead of going through a bespoke core method.

**Config schema is not a blocker.** `settings` config is open key/value (`parts/settings.xsd`
allows arbitrary `<setting name="…">`), so a plugin can introduce any `csrf.*` / `telemetry.*`
key with no XSD change. Only `databases.xml` has a dedicated closed schema, and that describes
the *core* database config shape (adapters plug in by alias, not schema).

**Namespaces are not a blocker.** Everything is PSR-4 under the single `Quiote\` root.
Composer merges multiple packages contributing to the same prefix as long as paths don't
overlap, so `Quiote\Mcp\*` can move to package `quioteframework/mcp` autoloading
`Quiote\Mcp\` → its own `src/` **with zero class renames**.

---

## 2. Core seam gaps to close FIRST (the critical path)

These are the only places where core *reaches into* a candidate subsystem by concrete
class. Until each is inverted, the subsystem cannot leave core without creating a
core→package→core dependency cycle. Ordered by how many extractions they unblock.

### 2.1 Decouple `PluginRegistrar` from `McpCatalog` — *blocks MCP* — DONE
`Quiote/Plugin/PluginRegistrar.php:8,126-179` hard-`use`d `Quiote\Mcp\McpCatalog` for the
`mcpTool()/mcpResource()/mcpPrompt()` convenience methods. This is the **only** core→MCP
reference. Fix: drop those three methods from core `PluginRegistrar`; the MCP package
registers catalog entries directly in its own `McpPlugin::register()` (it already imports
`McpCatalog`), or via a small package-owned registrar extension. No general mechanism is lost
— MCP is the only consumer.

### 2.2 Add a process/request lifecycle hook for plugins — *blocks OTel exporter* — DONE
`Quiote/Runtime/Kernel.php` called `TelemetryBootstrap::configureFromConfig()` and
`TelemetryBootstrap::flushAfterRequest()` by hard FQCN. Fixed: a new
`Quiote\Event\Lifecycle\WorkerRequestCompletedEvent` fires once per request from the worker
`$reset` closure (the per-request-boundary counterpart to the existing `KernelBootEvent`);
`Kernel` now emits both events and names no concrete plugin class. New
`Quiote\Telemetry\TelemetryPlugin` listens to both and drives `TelemetryBootstrap` — registered
as a core default in `Quiote::bootstrap()` today (added to `PluginManager`'s own plugin list so
its `Events::listen()` calls only run once, via `bootFromConfig()`'s existing idempotency guard).
Verified end-to-end: a real bootstrap still configures the SDK provider and flushes on request
completion, identically to before.

### 2.3 Make the default middleware stack extensible / remove opinionated entries — *blocks CSRF* — DONE
`Quiote/Middleware/MiddlewarePipeline.php` hardcoded `CsrfInjectionMiddleware`/
`CsrfValidationMiddleware` in its `$factories` core stack. Fixed:
`MiddlewareCatalog::registerAttributed()` now accepts an optional per-context factory (called
with the building pipeline's `Context`, not captured at plugin-registration time — needed
because a plugin's `register()` runs before any `Context` exists), so ordering still comes from
the class's own `#[Middleware]` attribute while construction can safely reach that context's
own `Controller` rather than risk the DI container autowiring an unrelated one. New
`Quiote\Security\Csrf\CsrfPlugin` registers both middleware this way; the hardcoded
`$factories` entries are gone. Registered as a core default in `Quiote::bootstrap()` today.
Verified end-to-end: a real bootstrap produces the identical middleware stack, same position.
The cosmetic CSRF name-drop in `MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT` is left
as-is — still accurate, since CSRF still runs by default via the core-default plugin call.

### 2.4 Add an exception-renderer registry — *blocks Whoops* — DONE
`Quiote/Middleware/ErrorHandlingMiddleware.php` did `new WhoopsRenderer()` directly. Fixed: new
`Quiote\Exception\Rendering\ExceptionRendererRegistry` (set-if-absent, mirrors
`DatabaseDriverRegistry`) plus `PluginRegistrar::developerExceptionRenderer()`. The middleware
now resolves through the registry and falls back to `SafeRenderer` if nothing is registered;
core registers `WhoopsRenderer` as the default in `Quiote::bootstrap()` today, guarded by
`class_exists(\Whoops\Run::class)`.

All four core-default registrations added by 2.2–2.4 (plus CSRF) are explicitly transitional —
each is one clearly-delineated, deletable block. When a subsystem actually moves to its own
package, that block is deleted and the app opts in via the `plugins` config key instead,
exactly like `Quiote\Mcp\McpPlugin` already works.

### 2.5 (Already clean — no core change) renderers, translators, user model, DB adapters, rate-limit
These select their implementation through existing config-driven registries/factories and
core never hard-references the concrete class:
- **Template renderers** (PHPTAL, XSLT): chosen per output-type via `View`/renderer config.
- **Translators** (Gettext): chosen per-domain via translation config `class`.
- **User model** (`RbacSecurityUser`): chosen via the user-factory config.
- **ORM adapters** (Eloquent/Doctrine/Cycle): attach via `DatabaseDriverRegistry` alias; core
  ships only `pdo`.
- **Rate limiting**: zero core references — pure lift-and-shift.

---

## 3. Candidate catalog

Ranked by value (dependency weight removed from a default install × how opinionated) vs
effort. "Dep in `require`" = currently forced on every install.

### Tier 1 — extract first

| Package (proposed) | Moves out of core | Dep freed | Core seam work | Effort |
|---|---|---|---|---|
| `quioteframework/mcp` | `packages/mcp/src/*` + `McpPlugin` | `mcp/sdk` (require) | §2.1 — DONE | **DONE (in-tree split)** — symlinked via path repo; not yet pushed to a standalone repo |
| `quioteframework/db-eloquent` | `packages/db-eloquent/src/*` | `illuminate/database` (dev) | none | **DONE (in-tree split)** — zero namespace change, already a plugin |
| `quioteframework/db-doctrine` | `packages/db-doctrine/src/*` | `doctrine/orm`+`dbal` (dev) | none | **DONE (in-tree split)** — zero namespace change, already a plugin |
| `quioteframework/db-cycle` | `packages/db-cycle/src/*` | `cycle/orm`+`database` (dev) | none | **DONE (in-tree split)** — zero namespace change, already a plugin |
| `quioteframework/whoops` | `packages/whoops/src/WhoopsRenderer` | `filp/whoops` (moved to package require) | §2.4 — DONE | **DONE (in-tree split)** — renamespaced `Quiote\Exception\Rendering\WhoopsRenderer` → `Quiote\Exception\Rendering\Whoops\WhoopsRenderer` (avoids sharing a namespace with core's `ExceptionRenderer`/`SafeRenderer`/`NegotiatesContent`, which stay in `Quiote\Exception\Rendering`) |
| `quioteframework/ratelimit` | `packages/ratelimit/src/*` | `symfony/rate-limiter` (moved to package require) | none | **DONE (in-tree split)** — zero namespace change |
| `quioteframework/csrf` | `packages/csrf/src/*` (incl. the 2 CSRF middleware, under `src/Middleware/`) | `symfony/security-csrf` (moved to package require) | §2.3 — DONE | **DONE (in-tree split)** — the 2 middleware renamespaced `Quiote\Middleware\Csrf*Middleware` → `Quiote\Security\Csrf\Middleware\Csrf*Middleware` (same reasoning as Whoops) |
| `quioteframework/telemetry-otel` | `packages/telemetry-otel/src/*` (tier-b: exporter/bootstrap) | `open-telemetry/*` (moved to package require) | §2.2 — DONE | **DONE (in-tree split)** — kept the shared `Quiote\Telemetry\*` namespace (see below) |
| `quioteframework/telemetry-dashboard` | `packages/telemetry-dashboard/src/{Dashboard,Command}/*` | `symfony/tui` (moved to package require) | not needed (see below) | **DONE (in-tree split)** — kept both shared namespaces (see below) |

All 9 Tier-1 rows are now DONE: verified via `composer test` (1874 tests, unchanged count) and
scratch end-to-end scripts that run a real `Quiote::bootstrap()` — CSRF middleware land in the
identical pipeline position under their new namespace, Whoops registers identically,
`Trace::span()` returns a real `Quiote\Telemetry\OtelSpanHandle` once telemetry is configured,
and `bin/quiote list` / `bin/quiote telemetry:dashboard --help` both work exactly as before with
no bootstrap required. The two ORM-adapter integration tests that need real DB containers
(Doctrine, Eloquent) were also re-run standalone to confirm the fixture-class autoload fix
below; the failures observed there are Docker/testcontainers environment flakiness (container
start timeouts), not autoload or namespace regressions — Cycle's own integration test, which
needs no external container beyond SQLite, passed cleanly.

**telemetry-otel/telemetry-dashboard needed two design calls the others didn't:**
- **Kept the shared namespace, unlike Whoops/CSRF.** `Quiote\Telemetry\*` splits into an
  always-on, SDK-free tier-a (`Trace`, `TraceRegistry`, `SpanKind`, the no-op handles,
  `MiddlewareSpanDecorator` — stay in `Quiote/Telemetry/`) and an OTel-SDK-backed tier-b
  (`TelemetryBootstrap`, `OtelSpanHandle`, `OtelMeterHandle`, `ForceSampleSampler`,
  `Psr7HeaderGetter`/`Setter`, `TelemetryPlugin` — moved to `packages/telemetry-otel/src/`), but
  the cross-references between them are extensive and *functional*, not just adjacent:
  `Trace::span()`/`Trace::current()` directly `new OtelSpanHandle(...)`, and `TraceRegistry` has
  a typed `?OtelMeterHandle` property it instantiates. Renaming tier-b (the CSRF/Whoops pattern)
  would have meant threading `use` imports through `Trace.php`, `TraceRegistry.php`, plus two
  *other* core files that also reference tier-b classes directly —
  `Quiote\Middleware\TelemetryMiddleware` (`new Psr7HeaderGetter()`) and
  `Quiote\Http\Client\HttpClient` (`new Psr7HeaderSetter()`). Instead, `packages/telemetry-otel`'s
  own `composer.json` declares the *same* `Quiote\\Telemetry\\` PSR-4 prefix as the kernel;
  Composer's longest-prefix-first resolution checks the package's `src/` first for any
  `Quiote\Telemetry\*` class and falls back to the kernel's own directory for the rest — verified
  correct for both tiers via a script that resolves classes from each. The same trick applies to
  `TelemetryDashboardCommand`, which keeps `Quiote\Console\Command\*` (shared with
  `AboutCommand`/`RoutesListCommand`/etc.).
- **`TelemetryDashboardCommand` stays eagerly registered, NOT moved to the generic
  plugin-command seam.** The extraction plan originally flagged this as "a well-supported path"
  (the same mechanism `mcp:serve` uses) — that undersold a real regression: `bin/quiote` builds
  `Console\Application` *before* any `Quiote::bootstrap()` call, so a plugin-contributed command
  only appears once a bootstrap has already run in the same process (a pre-existing, documented
  limitation in `docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md`'s "Command contribution boundary" —
  already true for `mcp:serve`, which never needed standalone `bin/quiote` invocation). Unlike
  `mcp:serve`, `telemetry:dashboard` is a standalone OTLP receiver/TUI process explicitly meant to
  run via `bin/quiote telemetry:dashboard` with no app bootstrap at all — moving it to the deferred
  seam would have silently broken that. Kept `Console\Application`'s direct
  `new TelemetryDashboardCommand()` call, only changing the guard from
  `class_exists(\Symfony\Component\Tui\Tui::class)` to `class_exists(TelemetryDashboardCommand::class)`
  (checking the package's own wrapper, consistent with the Whoops/CSRF/telemetry-otel core-default
  pattern) — verified via `bin/quiote list` and `bin/quiote telemetry:dashboard --help`.
- **`TelemetryPlugin`'s core-default registration in `Quiote::bootstrap()` needed a
  `class_exists()` guard it didn't have.** It was added unconditionally in §2.2 (safe at the
  time, since `TelemetryPlugin`/`TelemetryBootstrap` were still core and always autoloadable);
  once physically moved to a package that might not be installed, the unconditional
  `new \Quiote\Telemetry\TelemetryPlugin()` would fatal instead of degrading. Fixed to match the
  Whoops pattern: `if (class_exists(\Quiote\Telemetry\TelemetryPlugin::class)) { ... }`.

**Autoload gotcha hit and fixed:** the Doctrine/Cycle integration tests' entity fixtures
(`Quiote\Test\Database\Entity\{DoctrineUser,CycleUser}`) were initially declared in
`packages/db-doctrine|db-cycle/composer.json`'s own `autoload-dev` — this silently does
**nothing** while the package is installed as a path-repo dependency, because Composer only
ever loads a *dependency's* `autoload` section, never its `autoload-dev` (only the root
project's own `autoload-dev` is merged into the generated autoloader). Fixed by adding the
same PSR-4 mapping to the monorepo root's own `autoload-dev` (pointing at both packages'
`tests/Entity/` dirs — PSR-4 supports multiple directories per prefix). The packages' own
`autoload-dev` blocks are left in place with a comment explaining they'll activate once the
package is actually tested standalone post-split.

### Tier 2 — candidates the user hadn't flagged (from the hunt)

| Package (proposed) | Moves out of core | Dep freed | Notes |
|---|---|---|---|
| `quioteframework/phptal` | `Renderer/PhptalRenderer` | PHPTAL (**undeclared!**) | Also fixes a latent `require('PHPTAL.php')`; §2.5 clean |
| `quioteframework/xslt` | `Renderer/XsltRenderer` + `Util/QuioteXsltProcessor`, `SchematronProcessor` | `ext-xsl` (suggest) | §2.5 clean |
| `quioteframework/gettext` | `Translation/GettextTranslator` + `Translation/Gettext/*` | — | Largest opinionated code mass; interface layer (`ITranslator`, intl formatters, locale) **stays core** |
| `quioteframework/rbac` | `User/RbacSecurityUser` | — | Base `User`/`SecurityUser` identity stays core |
| `quioteframework/config-yaml` | `Config/Format/YamlFormatDriver` | `symfony/yaml` (**require**) | ⚠️ **bootstrap-ordering caveat**, see §5 |
| `quioteframework/session-pdo` | `Session/PdoSessionPersistence`, `Storage/PdoSessionStorage` | — | Easy slice of Session; full Session split is Tier 3 (CSRF/middleware entanglement) |

### Tier 3 / must-stay-core (evaluated, rejected)
- **`Telemetry` facade tier-a** (`Trace`, `TraceRegistry`, `SpanKind`, no-op handles,
  `MiddlewareSpanDecorator`): called from ~7 hot-path core sites; carries **zero** hard OTel-SDK
  dependency (lazy type hints only). Keep in core — it's the always-safe no-op contract.
- **`Http/Client`**: it *is* the telemetry egress seam and a first-class plugin hook
  (`configureHttpClients`). Stays.
- **`Logging/Sink`**: already fully decoupled behind `SinkInterface`; nothing to fix.
- **`middlewares/negotiation`, `middlewares/payload`, `symfony/mime`, `symfony/console`**:
  core request/CLI plumbing.
- **Core Database abstraction** (`Database`, `DatabaseManager`, `DatabaseDriverRegistry`,
  `AbstractOrmDatabase`, `PdoDatabase`): the contract adapters build on. Stays. (Legacy
  `PropelDatabase`/`Doctrine2*Database` are separate dead-code cleanup, tracked in
  `docs/DATABASE_ADAPTERS_PLAN.md`.)

---

## 4. Distribution & packaging model

No monorepo/subtree tooling exists yet. Recommended: **monorepo-with-read-only-splits**
(the Symfony/Laravel model), not hand-maintained separate dev repos.

- **Develop in-tree**: subsystems stay in the `quiote` repo under `Quiote/<Sub>/` so the full
  integration test suite keeps exercising them together against the kernel.
- **Split on tag**: a GitHub Action (e.g. `splitsh/lite`) mirrors each subsystem subtree to a
  read-only `quioteframework/<pkg>` repo, so Packagist serves `composer require
  quioteframework/csrf`. Consumers never see the monorepo.
- **Per-package `composer.json`**: each split carries its own manifest — `require:
  quioteframework/quiote` (kernel) + its freed dependency (e.g. `filp/whoops`), PSR-4 for its
  slice of the `Quiote\` namespace, and (where relevant) its own `suggest`.
- **Versioning**: lockstep-tag all packages to the kernel version initially (simplest;
  matches the monorepo model). Kernel declares each as `suggest`, never `require`.
- **Optional meta-package** `quioteframework/quiote-full` that requires the common drop-ins,
  for users who want today's batteries-included install in one line.
- **Scaffolding**: `NewCommand`/`AppWriter` should offer a "batteries" toggle that adds the
  common plugins to the generated `plugins` config + `composer.json`.

---

## 5. Risks & caveats

- **Bootstrap-phase ordering (YAML driver).** Plugins boot *after* config loads
  (`PluginManager::bootFromConfig()` runs inside `Quiote::bootstrap()`), but a config **format
  driver** must exist *before* config is read — chicken/egg. `quioteframework/config-yaml`
  therefore cannot register through the normal `plugins` key; it needs a pre-bootstrap
  registration path (composer autoload + a `FormatDriverRegistry` entry loaded eagerly), or it
  stays in core. Flag before committing to extracting it.
- **Behavior change: CSRF becomes opt-in.** Today CSRF is on-by-default (in the core stack,
  gated by `core.csrf.enabled`). After extraction it's "on when the plugin is installed." This
  is a **breaking change** for existing apps — document loudly, ship in a major bump, and have
  the scaffolder include `quioteframework/csrf` by default. Same posture for anything currently
  in the default middleware stack.
- **The telemetry facade must not follow the exporter out.** Extracting tier-b/c while keeping
  tier-a in core is the whole point; conflating them would force rewriting every `Trace::span()`
  call site behind an indirection. Keep the boundary crisp.
- **Deps currently mis-filed as `require`.** `filp/whoops`, `symfony/security-csrf`,
  `symfony/rate-limiter`, `symfony/yaml`, and the entire `open-telemetry/*` + `symfony/tui` set
  are in the main `require` block despite serving optional features. Extracting Tier-1 directly
  slims the default install; even before extraction, several could move to `suggest`.
- **PHPTAL is an undeclared dependency** (`require('PHPTAL.php')` at runtime, absent from
  `composer.json`). Extraction is also the fix for this correctness bug.
- **Two `SessionMiddleware` files** appear to exist (`Quiote/Session/` and `Quiote/Middleware/`)
  — confirm which is live before touching Session.

---

## 6. Suggested sequencing

1. ~~**Enabling core work** (no extraction yet, all internal, fully testable): §2.1 MCP
   decouple, §2.4 renderer registry, §2.2 lifecycle hooks, §2.3 middleware-stack
   extensibility.~~ **DONE.**
2. **Distribution scaffolding**: set up the split Action + one throwaway package to prove the
   pipeline end-to-end. **Partially done** — the `packages/*` path-repository mechanics are
   proven (9 packages symlinked and green); the actual `splitsh/lite` GitHub Action
   (`docs/MONOREPO_SPLIT_PLAN.md`'s `split.yml`) has **not** been built yet — still the next
   step before any package is consumable outside this monorepo.
3. ~~**Lift-and-shift the already-clean plugins** (lowest risk, immediate dependency wins):
   ORM adapters, MCP, rate-limit, Whoops, telemetry-dashboard.~~ **DONE** — `mcp`,
   `db-eloquent`, `db-doctrine`, `db-cycle`, `ratelimit`, `whoops`, and `telemetry-dashboard`
   are all physically split into `packages/*`.
4. ~~**Extractions needing the new seams**: CSRF, telemetry-otel.~~ **DONE** —
   `packages/csrf/` and `packages/telemetry-otel/` both split.
5. **Tier 2 opinionated code**: PHPTAL/XSLT, Gettext, RBAC, (YAML pending §5), session-PDO.
   **Not started.**
6. **Docs + meta-package + scaffolder toggle**; announce BC breaks. **Not started** — no
   `quioteframework/quiote-full` meta-package yet, and the CSRF/rate-limit/etc. "opt-in"
   posture is still nominal: `Quiote::bootstrap()`'s core-default calls mean nothing has
   actually become opt-in-by-absence yet (removing a core-default call, and the resulting BC
   break, is deliberately deferred to whenever each package is ready to leave the monorepo for
   real).
</content>
