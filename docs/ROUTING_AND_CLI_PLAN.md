# Attribute Routing + `quiote` CLI Plan

## Background — what actually exists today

Two surprises from surveying the current code shape the whole design:

1. **The runtime is already Symfony-routing-based.** `Quiote\Routing\Routing`
   matches requests with `Symfony\Component\Routing\Matcher\UrlMatcher` over a
   `Symfony\Component\Routing\RouteCollection` (`Routing.php`). A matched route
   yields `_module` / `_action` / `_output_type` / `_route` + path params, which
   `RoutingMiddleware` stashes as request attributes and `DispatchMiddleware`
   turns into an `ActionDescriptor`. So we are **not** introducing Symfony
   routing — it's already the engine. We're adding a new way to *populate* the
   `RouteCollection`.

2. **`routing.xml` is not actually compiled.** `config_handlers.xml` registers
   a `Quiote\Config\RoutingConfigHandler` for `routing.xml`, but **that class
   does not exist**. Routes are instead hand-generated into committed PHP under
   `Routing/Generated/` (each module exposes
   `addRoutes(RouteCollection $routes, array &$meta)`), produced by a one-off
   `generate_symfony_routes.php` script and committed. There is no live route
   compilation path at all.

Alongside the `RouteCollection`, `Routing` keeps a `$meta` array
(`gen_path` / `cut` / `path` per route name) that powers Quiote's own regex-based
`Routing::gen()` reverse-routing — this is richer than vanilla Symfony (Quiote
routing.xml supports nested routes, `cut`/`stop`/`imply`, locale-setting routes,
and source-based matching like `_SERVER[HTTP_ACCEPT]`).

There is **no** `symfony/console` dependency and no CLI worth keeping (the old
Phing binary is being deleted and is out of scope).

## Goals

1. Declare routes with a `#[Route]` attribute on action classes, discovered
   automatically — no hand-generated `Routing/Generated/` files, no XML required
   for the common case.
2. A new `quiote` CLI on `symfony/console` with, initially:
   - `routes:list` — a readable table of all routes (name, path, methods,
     module, action).
   - `routes:compile` — precompile routing into a static, opcache-friendly,
     cacheable artifact, with `--check` (drift detection for CI) and dry-run.

## Design principle: IR-first, mirroring what's already here

This is the same shape as the validator compiler (`ValidatorPlan`) and the
config-format work (`IArrayConfigHandler` canonical array): **decouple the
source of routes from the thing that consumes them, via a format-independent
intermediate representation.**

```
sources (front-ends)          IR                     back-ends (emitters)
────────────────────          ──                     ────────────────────
#[Route] attributes ─┐                          ┌─► RouteCollectionBuilder (runtime: RouteCollection + $meta)
                     ├─► RoutePlan / Route... ───┤
routing.xml (future) ┘   RouteDefinition[]       └─► CompiledMatcherEmitter (routes:compile: static cacheable PHP)
programmatic (future)                            └─► table rows (routes:list)
```

The attribute scanner is the first front-end; a future `RoutingConfigHandler`
(or a programmatic builder) can feed the *same* `RoutePlan` without touching the
emitters. The compiled-matcher emitter and the runtime builder both consume the
`RoutePlan` and neither cares where the routes came from.

---

## Feature A — `#[Route]` attribute routing

### A1. The attribute

Define `Quiote\Routing\Attribute\Route` (repeatable, target = class):

```php
#[Route('/products/{id}', name: 'products.view', methods: ['GET'], requirements: ['id' => '\d+'], outputType: 'html')]
final class ViewAction extends Action { public function executeRead(WebRequest $rd) { ... } }
```

- **Placed on the action class, not on methods.** Quiote's model is "one action
  class, multiple HTTP-verb methods" (`executeRead`/`executeWrite`/...), which is
  the opposite of Symfony MVC's "one controller, many route methods." So a class
  carries one-or-more routes; `methods:` restricts which HTTP verbs the route
  accepts (and `HttpMethodMapper` already maps verb → `execute*`).
- `module` / `action` are **derived** from the class, not repeated in the
  attribute: namespace → module (`{namespace_prefix}\Modules\{Module}\Actions`,
  using the existing `core.namespace_prefix` setting), class name → action
  (`{Action}Action` → `Action`, dotted for nested actions).
- Field names mirror Symfony's own `#[Route]` where they overlap (`path`,
  `name`, `methods`, `requirements`, `defaults`, `host`, `condition`,
  `priority`) so it's familiar; `outputType` is the one Quiote-specific addition.
- We define our own attribute rather than reusing
  `Symfony\Component\Routing\Attribute\Route` because Symfony's attribute loader
  assumes controller-method → route mapping, which doesn't fit our class-level
  model — but the attribute is just a data holder, so staying field-compatible
  costs nothing and keeps the door open.

### A2. Scanner → `RoutePlan` IR

- `Quiote\Routing\Compiler\RouteDefinition` (immutable): name, path, module,
  action, methods[], defaults[], requirements[], host, condition, priority,
  outputType, plus the derived Quiote `meta` (`gen_path`/`path`; `cut` defaults
  false for attribute routes — see "what this is NOT").
- `Quiote\Routing\Compiler\RoutePlan`: ordered `RouteDefinition[]` + source ref.
- `Quiote\Routing\Compiler\AttributeRouteScanner`: globs
  `%core.module_dir%/*/Actions/*Action.php` (+ nested-action subdirs), derives
  each FQCN from `core.namespace_prefix` + path, uses **reflection** to read
  `#[Route]` attributes (reflection reads attributes without instantiating or
  running the action — same approach Symfony's own attribute loader uses), and
  builds the `RoutePlan`.
- Emits `Diagnostic`s (reuse the validator compiler's `Diagnostic` shape) for
  duplicate route names, duplicate paths+methods, and an action with no
  `#[Route]` that also has no XML/programmatic route (potential dead action —
  warning, not error).

### A3. `RouteCollectionBuilder` (runtime back-end)

- `RoutePlan` → `Symfony\Component\Routing\RouteCollection` + `$meta` array,
  exactly the pair `Routing` already expects.
- Wire into the base `Routing` class so an app opts in: dev mode scans on boot;
  prod uses the compiled artifact from Feature B (falls back to a live scan if
  absent). This replaces the committed `Routing/Generated/` files for
  attribute-declared routes.

### A4. Tests

- Fixture module with a few `#[Route]` actions (flat, parameterized,
  method-restricted, nested action name).
- Assert the built `RouteCollection` has the expected routes and that matching
  representative paths yields the right `_module`/`_action`/params — i.e. test
  through the real `UrlMatcher`, not just the builder's internal state.
- Assert diagnostics fire on duplicate name / duplicate path.

---

## Feature B — the `quiote` CLI

### B1. Dependency + entrypoint

- Add `symfony/console` (`^8.0`, matching the other Symfony components) to
  `composer.json` `require`; set `bin` to the new `bin/quiote`.
- `bin/quiote`: resolve app root (`--app-dir`, else `QUIOTE_APP_DIR`, else
  upward search from CWD for `Config/settings.*`), set `core.app_dir`,
  `Quiote::bootstrap($env, 'console')`, build the Console `Application`, register
  commands. `$env` from `--env`/`QUIOTE_ENV`, default `development`.
- Introduce a `console` context (settings) — none exists yet. Keep it minimal
  (no CSRF/session/output-type machinery needed for CLI).

### B2. Console application skeleton

- A thin `Quiote\Console\Application` (extends Symfony's) that pulls command
  instances from the DI `Container` (`make()`), so commands get constructor
  injection like actions/services do.
- Prove the harness end-to-end with one trivial command (`about`, printing
  framework/version/app-dir/env) + a test that runs it via Symfony's
  `CommandTester`. This is the "does the bootstrap-in-console-context path even
  work" gate before building real commands on top.

### B3. `routes:list`

- Resolves routes via the same `AttributeRouteScanner` → `RoutePlan`.
- Renders a `SymfonyStyle` table: name, path, methods, module, action, output
  type. Flags: `--module=` filter, `--json` (machine-readable), `--sort=`.
- Surfaces scanner `Diagnostic`s (dup names etc.) as warnings in the output.

### B4. `routes:compile`

This is the "precompile routing data to static files that can be cached" ask.

- Back-end emitter uses Symfony's **`CompiledUrlMatcherDumper`** (and
  `CompiledUrlGeneratorDumper` if/when we move `gen()` — see decision points) to
  dump the `RouteCollection` into the static PHP array form that
  `CompiledUrlMatcher` consumes with **zero per-request matcher compilation**.
  Today a fresh `UrlMatcher` is built from the collection on every request; the
  compiled matcher is the real performance win here.
- The compiled artifact is a plain `<?php return [...];` file — opcache-friendly,
  and loadable through the existing APCu config-cache path for FrankenPHP-style
  workers.
- Output is per **(environment, context)** — routes are context-specific
  (web/api/console), so the cache name must include both, mirroring
  `ConfigCache::getCacheName()`.
- **Reuse the validator compiler's artifact utilities**: `EmittedArtifact`
  (source + sha256 + target hint), `FilesystemArtifactWriter` (atomic
  temp-then-rename), `ArtifactDriftChecker` (checksum compare, never writes).
- Flags: `--check` (emit in-memory, compare to committed/cached file, non-zero
  exit on drift — for CI), `--dry-run`/stdout, `--context=`, `--env=`.
- Runtime loader: `Routing` prefers the compiled matcher artifact when present,
  else falls back to a live `AttributeRouteScanner` + `RouteCollectionBuilder`
  build (so dev works with zero compile step, prod is fast).

---

## Shared refactor (small, do first)

`EmittedArtifact`, `ArtifactWriter`/`FilesystemArtifactWriter`,
`ArtifactDriftChecker`, and `Diagnostic` currently live under
`Quiote\Validator\Compiler`. A second consumer (routing) now exists, so promote
the genuinely generic ones to a shared namespace (e.g.
`Quiote\Support\Compiler`), leaving thin aliases or updating the validator
references. Guard the move with the existing validator-compiler tests
(byte-identical behavior) before building routing on top — same discipline as
the config-handler golden tests.

---

## What this is NOT (honest scope limits)

- **Attribute routing does not replace `routing.xml` wholesale on day one.** XML
  routing.xml supports nested routes, `cut`/`stop`/`imply`, locale-setting
  routes, callbacks, and source-based matching (`_SERVER[HTTP_ACCEPT]`) that a
  flat `#[Route]` attribute cannot express yet. Attributes cover the common flat
  and parameterized cases; anything using those advanced features stays on
  XML/programmatic routing until (and if) the attribute + IR grow to model them.
  This is a strangler addition, not a big-bang replacement.
- **Not changing `Routing::gen()` semantics initially.** Reverse routing keeps
  using the Quiote `$meta` array + regex `gen()`, fed from the new pipeline, so
  every existing template/redirect `gen('name', [...])` call and every route
  name stays valid. Migrating to Symfony's `CompiledUrlGenerator` is a separate,
  later, opt-in step (see decision points) precisely because it could change
  URL-generation edge cases across the whole app.
- **Not implementing the missing `RoutingConfigHandler` here** — though the
  IR-first design makes it a natural future front-end (XML → `RoutePlan`). We
  either implement it later against the IR or remove its dead
  `config_handlers.xml` registration; flagged as a decision point, not silently
  left dangling.
- **Not deleting `Routing/Generated/` yet** — attribute routes and the compiled
  artifact supersede it, but removal waits until the sandbox app is migrated and
  green, so the reference app keeps working throughout.

## Decision points (need a call before/along the way)

1. **`gen()` / reverse routing**: keep Quiote's meta+regex `gen()` (safe, my
   recommendation) vs. adopt Symfony's compiled generator (faster, standard, but
   risks URL-generation behavior changes app-wide). Recommend: keep for now,
   revisit as its own task.
2. **`RoutingConfigHandler`**: implement as an XML→`RoutePlan` front-end, or
   delete the dead registration? Recommend: delete the dead registration now
   (it's a lie in `config_handlers.xml` today), implement later only if XML
   routing needs to survive alongside attributes.
3. **Shared-compiler namespace**: `Quiote\Support\Compiler` vs. leave in
   `Quiote\Validator\Compiler` and just reference across. Recommend: promote —
   two consumers justify it.
4. **Scan strategy**: reflection (recommended, matches Symfony, correct) vs.
   static token parsing (no autoload side effects, but reimplements attribute
   parsing). Recommend: reflection, since compile is an offline step.

## Sequencing

1. Shared-compiler refactor (guarded by existing validator tests).
2. **Feature A** — attribute + IR + scanner + `RouteCollectionBuilder`, wired
   into `Routing` behind an opt-in, with tests through the real matcher.
3. **Feature B1–B2** — `symfony/console`, `bin/quiote`, console context,
   Application skeleton + trivial command + CommandTester test.
4. **B3** `routes:list` (read-only, low risk, exercises the scanner via the CLI).
5. **B4** `routes:compile` (compiled matcher emitter + artifact reuse + `--check`
   + runtime loader).
6. Migrate the sandbox app to attribute routes; once green, retire its
   `Routing/Generated/` files. Full-suite + APCu + random-order regression after
   each step, same as prior work.

## Testing strategy

- Attribute scanner / builder: fixture module, assert via real `UrlMatcher`.
- `routes:compile`: golden-file the emitted artifact (deterministic, no
  timestamps — same rule as the config golden tests); assert the compiled
  matcher matches the same paths as the live build (parity test); assert
  `--check` exits non-zero on drift.
- CLI commands: Symfony `CommandTester` for `about`, `routes:list`,
  `routes:compile`.
- No regressions in the existing 1400+ suite; add the routing/CLI tests on top.
