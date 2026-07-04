# Monorepo + Read-Only Split — how publishing works

## Status — proposed

Companion to `docs/PLUGIN_EXTRACTION_PLAN.md` (§4 there is the summary; this doc is the
detail). Describes the mechanics of developing every plugin package **in this repo** while
publishing each as an independently `composer require`-able package, plus measured vendor
directory size impact.

This is the model Symfony (`symfony/symfony` → `symfony/routing`, `symfony/console`, …) and
Laravel (`laravel/framework` → `illuminate/*`) both use, via [`splitsh/lite`]
(https://github.com/splitsh/lite) or the older `git subtree split`. We use `splitsh/lite`
(a Go tool, orders of magnitude faster than `git subtree split` on a repo this age).

## Why monorepo-with-splits, not separate hand-maintained repos

Separate repos from day one means: every cross-cutting change (e.g. §2 of the extraction
plan, which touches core *and* the CSRF package in the same PR) becomes a multi-repo,
multi-PR, ordering-sensitive dance, and the integration test suite can't exercise
core+plugin together in CI. A monorepo with automated splits gets both:

- **One PR, one CI run** for changes spanning the kernel and a plugin's package boundary.
- **Consumers still see small, independent packages** — `composer require
  quioteframework/csrf` never pulls MCP, Doctrine adapters, etc.
- **Tags stay in lockstep** — cutting `v2.3.0` on the monorepo produces `v2.3.0` on every
  split repo in the same action run, so version constraints between kernel and plugins are
  trivial (`quioteframework/csrf: ^2.3` always matches a real kernel `v2.3.x`).

The cost: a bit of CI machinery up front, and a rule that plugin code must live under a
directory boundary the splitter can cut cleanly (one directory → one package, no shared files
that need to land in two split repos).

## Directory layout

Each extractable subsystem gets **its own top-level directory** in the monorepo — not nested
under `Quiote/`, because `splitsh/lite` splits along a path prefix, and a package's `composer.json`
+ its own tests need to live inside that same prefix (whereas today's tests live centrally
under `tests/`). Proposed layout:

```
quiote/                                # kernel — stays as-is
├── Quiote/                            #   (Mcp/, Security/Csrf/, etc. move OUT of here)
├── composer.json                      #   requires nothing plugin-specific
├── tests/                             #   kernel tests only, post-migration
│
├── packages/
│   ├── mcp/
│   │   ├── composer.json              # name: quioteframework/mcp
│   │   ├── src/                       # PSR-4 Quiote\Mcp\ -> src/
│   │   │   └── ...                    # (McpPlugin.php, McpCatalog.php, ...)
│   │   └── tests/
│   ├── csrf/
│   │   ├── composer.json              # name: quioteframework/csrf
│   │   ├── src/
│   │   └── tests/
│   ├── ratelimit/
│   ├── whoops/
│   ├── db-eloquent/
│   ├── db-doctrine/
│   ├── db-cycle/
│   ├── telemetry-otel/
│   ├── telemetry-dashboard/
│   ├── phptal/
│   ├── xslt/
│   ├── gettext/
│   ├── rbac/
│   └── session-pdo/
│
└── .github/workflows/split.yml        # the splitter action (§below)
```

Each `packages/<name>/` is a **self-contained composer package root**: its own
`composer.json`, its own `src/` (still PSR-4 `Quiote\Mcp\` etc. — no class renames), its own
`tests/`. `splitsh/lite` takes `packages/mcp` as a path prefix and rewrites history so that
prefix becomes the root of a new repo — i.e. `quioteframework/mcp`'s `composer.json` ends up
at the *root* of that split repo, exactly what Packagist expects.

The root `composer.json` gains a **path repository** pointing at `packages/*` (see §"Local
dev experience" below) so the monorepo's own test suite / sample app can `composer require`
a plugin from the working tree without waiting for a published tag.

## The split mechanics, step by step

1. **Author works normally** in `packages/csrf/` inside the `quiote` monorepo — same PR can
   touch `Quiote/Middleware/MiddlewarePipeline.php` (core) and
   `packages/csrf/src/CsrfPlugin.php` (package) together, reviewed and tested as one unit.
2. **On merge to `main`** (or on tag push — see versioning below), a GitHub Action runs
   `splitsh/lite` once per package directory:
   ```yaml
   - uses: docker://danharrin/monorepo-split-github-action:latest
     with:
       package-directory: 'packages/csrf'
       repository-organization: 'quioteframework'
       repository-name: 'csrf'
       user-name: 'quiote-bot'
       user-email: 'bot@quiote.dev'
   ```
   (The `danharrin/monorepo-split-github-action` wraps `splitsh/lite` + the push step; this is
   the same action Filament/Livewire use. A hand-rolled `splitsh/lite` + `git push` step works
   identically if we'd rather not depend on a third-party action.)
3. **The action force-pushes the rewritten history** (containing only commits that touched
   `packages/csrf/**`, with paths rewritten so `packages/csrf/composer.json` → `composer.json`)
   to `github.com/quioteframework/csrf`. That repo is **read-only for humans** — a `CONTRIBUTING.md`
   there says "PRs go to quioteframework/quiote monorepo".
4. **On a tag push** (`v2.3.0` on the monorepo), the same job additionally tags each split repo
   `v2.3.0` at the commit it just pushed. Packagist (configured once per split repo, or via a
   Packagist "organization" auto-hook) picks up the new tag and publishes the version.
5. **Consumers** run `composer require quioteframework/csrf` and get a small package whose
   `composer.json` requires `quioteframework/quiote: ^2.3` — normal composer resolution, no
   monorepo awareness needed on their end.

## Local dev experience (working across the boundary before a split runs)

Two things matter during active development, before a package has ever been split/tagged:

- **The sample app / integration tests** need to `use` a plugin that only exists as
  `packages/csrf/` in-tree. Add a `path` repository to the root `composer.json`:
  ```json
  "repositories": [
    { "type": "path", "url": "packages/*", "options": { "symlink": true } }
  ]
  ```
  Then `samples/app`'s `composer.json` can `"require": {"quioteframework/csrf": "*"}` and
  composer symlinks `vendor/quioteframework/csrf` → `packages/csrf/` — edits are live, no
  publish step needed for local iteration.
- **New packages start life** with a `composer.json` of their own (`name`, `require:
  quioteframework/quiote`, PSR-4 autoload) from day one — don't defer that to "when we
  actually split it", since the path-repo trick above depends on it existing.

## Versioning policy

**Lockstep**, at least initially: every split package is tagged with the exact same version
as the kernel on every kernel release, whether or not that package changed. This is the
simplest policy and matches how `symfony/symfony` version-gates its components (though
Symfony's individual components *can* diverge on patch versions — we don't need that
complexity yet). Concretely:
- Kernel `composer.json` never lists a plugin in `require`; at most `suggest`.
- Each plugin's `composer.json` requires `quioteframework/quiote: ^<major>.<minor>`.
- A plugin can't be installed against an incompatible kernel — composer enforces the version
  constraint like any other dependency.
- If a plugin needs an urgent fix independent of a kernel release, cut a kernel patch release
  too (even with no kernel-visible change) rather than diverging version schemes. Revisit only
  if this proves painful in practice.

## What ships where — composer.json split (concrete, from the current tree)

| Stays in kernel `require` | Moves to a plugin's `require` |
|---|---|
| `psr/*`, `symfony/routing`, `symfony/cache`, `symfony/console`, `symfony/mime`, `nyholm/psr7*`, `relay/relay`, `middlewares/negotiation`, `middlewares/payload` | `filp/whoops` → `quioteframework/whoops` |
| | `symfony/security-csrf` → `quioteframework/csrf` |
| | `symfony/rate-limiter` → `quioteframework/ratelimit` |
| | `symfony/yaml` → `quioteframework/config-yaml` (pending the bootstrap-ordering caveat in the extraction plan §5) |
| | `open-telemetry/api`, `-sdk`, `-sem-conv`, `-exporter-otlp`, `-context` → `quioteframework/telemetry-otel` |
| | `symfony/tui` → `quioteframework/telemetry-dashboard` |
| | `doctrine/orm`, `doctrine/dbal` → `quioteframework/db-doctrine` |
| | `illuminate/database` → `quioteframework/db-eloquent` |
| | `cycle/orm`, `cycle/database` → `quioteframework/db-cycle` |
| | `mcp/sdk` → `quioteframework/mcp` |

All of the right-hand column packages currently sit in the kernel's `require` (not
`require-dev`/`suggest`) except the three DB ORMs and `mcp/sdk`, which are already
`require-dev`+`suggest` (i.e. already **not** forced on production installs — only on
`composer install --dev`/CI). The extraction mainly matters for the left-column-turned-right
items: Whoops, CSRF, rate-limiter, YAML, OTel, and TUI are forced on *every* production
install today.

## Measured vendor directory impact

Current `vendor/` on this repo (dev install, includes `require-dev`): **126 MB**. Breakdown of
the packages this plan would move out of a default/production install, measured directly
(`du -sh`) against the current `vendor/` tree:

| Package | Size | Destination package | Currently |
|---|---|---|---|
| `filp/whoops` | 364 KB | `quioteframework/whoops` | prod `require` |
| `symfony/security-csrf` | 92 KB | `quioteframework/csrf` | prod `require` |
| `symfony/security-core` *(transitive of security-csrf)* | 992 KB | `quioteframework/csrf` | prod `require` (transitive) |
| `symfony/rate-limiter` | 160 KB | `quioteframework/ratelimit` | prod `require` |
| `symfony/yaml` | 216 KB | `quioteframework/config-yaml` | prod `require` |
| `symfony/tui` | 980 KB | `quioteframework/telemetry-dashboard` | dev-only (`require-dev`) |
| `open-telemetry/*` (api+sdk+sem-conv+exporter-otlp+context) | 4.4 MB | `quioteframework/telemetry-otel` | dev-only (`require-dev`) |
| `doctrine/orm` + `doctrine/dbal` | 7.1 MB | `quioteframework/db-doctrine` | dev-only |
| `illuminate/database` (+ `nesbot/carbon` etc. transitively, 5.8 MB) | 4.7 MB + 5.8 MB | `quioteframework/db-eloquent` | dev-only |
| `cycle/orm` + `cycle/database` | 2.6 MB | `quioteframework/db-cycle` | dev-only |
| `mcp/sdk` | 1.6 MB | `quioteframework/mcp` | dev-only |

**Production-install win (what actually forces bloat on every deploy today):**
`filp/whoops` + `symfony/security-csrf` + `security-core` + `symfony/rate-limiter` +
`symfony/yaml` ≈ **1.8 MB** freed from the default `composer install --no-dev` — small in
absolute terms, but 100% of it is code that runs on 0% of requests unless the app opted in
(Whoops needs `core.developer_exceptions=true`; CSRF/rate-limit are always-loaded classes
even if a given app path never triggers them).

**Dev/CI-install win** (the bigger number, relevant to contributor clone size and CI cache):
OTel + Doctrine + Eloquent(+Carbon) + Cycle + `mcp/sdk` + `symfony/tui` ≈ **~27 MB**, currently
forced onto every `composer install` a contributor or CI runner does even if they're working
on, say, the routing layer. Post-extraction, the kernel's own `composer test` no longer
needs any of these — each plugin package tests itself against the kernel via the path-repo
mechanism, with its own ORM/SDK dependency in its own `require-dev`.

Note `rector/` (27 MB), `phpstan/` (27 MB), and `phpunit/` (8.3 MB) are kernel tooling
unaffected by this plan — they stay in the kernel's `require-dev` regardless of extraction.

## CI changes required

- **`.github/workflows/tests.yml`**: today's single `composer test` run against one `vendor/`
  needs to become: kernel tests (no plugin deps installed) + one job per plugin package that
  installs *that* package's own deps (via the path-repo, so still fully in-tree) and runs its
  own test suite. This is the mechanism that actually proves each plugin's `composer.json` is
  self-sufficient — a plugin that silently depends on some other plugin's dev dependency being
  present would fail its isolated CI job.
- **New `.github/workflows/split.yml`**: runs the split action (§ above) on push to `main`
  (continuous split, cheap — most pushes touch one or zero package dirs so most jobs no-op)
  and on tag push (does the version tagging).
- **Packagist**: one-time setup per split repo — either register each
  `quioteframework/<pkg>` repo individually, or use a GitHub org webhook / Packagist "update
  packages from GitHub organization" integration so new split repos auto-register.

## Migration order (mechanical, once §2 of the extraction plan lands)

1. ~~Create `packages/<name>/composer.json` + move `Quiote/<Sub>/*` → `packages/<name>/src/`
   (`git mv`, preserves blame) for one already-clean plugin (recommend `mcp` — smallest core
   seam, per extraction plan §2.1).~~ **DONE.** `packages/mcp/{composer.json,src/,tests/}`
   exists; `Quiote\Mcp\*` moved with zero class renames (`git mv` preserved history/blame).
2. ~~Add the path repository to root `composer.json`; verify `samples/app` still boots MCP via
   the path-installed package.~~ **DONE.** Root `composer.json` has the `packages/*` path
   repository (symlink), requires `quioteframework/mcp: @dev` in `require-dev` (the `@dev`
   stability flag is needed because a path-repo package with no VCS tags resolves to
   `dev-main`, below composer's default minimum-stability), and `vendor/quioteframework/mcp`
   symlinks to `packages/mcp/`. `mcp/sdk` moved from the kernel's `require-dev` into
   `packages/mcp/composer.json`'s own `require`. `packages/mcp/tests/` runs alongside the
   kernel's own suite via an extra `<directory>` line in `tests/config/phpunit.xml` (see that
   file's comment) — not yet its own isolated CI job (§"CI changes required" above, still
   pending). Full `composer test` (1874 tests) verified green after the move, plus a scratch
   end-to-end boot confirming `Quiote\Mcp\McpPlugin` resolves and registers identically to
   before.
3. Stand up `split.yml`, dry-run against a scratch org/repo before pointing at
   `quioteframework/mcp` for real. **Not yet done** — no GitHub Action or split repo exists
   yet for ANY package; this remains the next step before any of them is actually consumable
   as a standalone package outside this monorepo.
4. ~~Repeat step 1 for each remaining Tier-1 package once its core seam gap (extraction plan
   §2) is closed~~ **DONE for 6 of 8 Tier-1 packages**: `ratelimit`, `csrf`, `whoops`,
   `db-eloquent`, `db-doctrine`, `db-cycle` all now have `packages/<name>/{composer.json,src/,
   tests/}`, following the same pattern as `mcp` — path repo `@dev` require-dev entry, symlinked
   install, tests wired into `tests/config/phpunit.xml`'s `unit` suite. Two extra things this
   round surfaced, worth knowing before repeating the pattern for `telemetry-otel`/
   `telemetry-dashboard`:
   - **Shared-namespace moves need a rename, not a lift.** `Quiote\Mcp\*` could move verbatim
     because nothing else lived in that namespace. `WhoopsRenderer` and the two CSRF
     middleware did NOT have that luxury — they shared a namespace (`Quiote\Exception\Rendering`,
     `Quiote\Middleware`) with classes that stay in core. Renamed
     `Quiote\Exception\Rendering\WhoopsRenderer` → `Quiote\Exception\Rendering\Whoops\WhoopsRenderer`
     and `Quiote\Middleware\Csrf{Injection,Validation}Middleware` →
     `Quiote\Security\Csrf\Middleware\Csrf{Injection,Validation}Middleware` — small, mechanical,
     but not "zero renames"; every FQCN reference (a handful of core call sites + tests) had to
     follow. The alternative (declare the *same* namespace from two packages' separate
     `autoload` entries, letting Composer's longest-prefix-first PSR-4 resolution pick the right
     directory per class) works too and was considered, but a clean per-package namespace is
     less surprising long-term.
   - **A dependency package's own `autoload-dev` is inert under a path repository.** Composer
     only merges a *root* project's `autoload-dev` into the generated autoloader; a required
     package's `autoload-dev` (even path-repo, even symlinked) is never consulted. Hit this with
     the Doctrine/Cycle integration tests' entity fixtures (declared in
     `packages/db-doctrine|db-cycle/composer.json`'s `autoload-dev`, silently unresolvable) — fixed
     by duplicating the mapping into the monorepo root's own `autoload-dev` (PSR-4 supports
     multiple directories per prefix, so both packages' `tests/Entity/` map to the same
     `Quiote\Test\Database\Entity\` prefix without conflict). The packages' own `autoload-dev`
     stays declared for when each is actually tested standalone post-split.
   - Verification for this round: full `composer test` (1874 tests, unchanged count), a scratch
     end-to-end bootstrap confirming the CSRF middleware still land in the identical pipeline
     position under their new namespace and Whoops still registers, and a targeted rerun of the
     Doctrine/Cycle/Eloquent Docker integration tests to confirm the entity-fixture fix (Cycle's,
     which needs no external DB container, passed cleanly end-to-end; Doctrine/Eloquent's
     container-dependent assertions hit environment-level Docker timeouts unrelated to the
     autoload fix).
5. ~~`telemetry-otel` and `telemetry-dashboard` remain the two un-split Tier-1 packages~~
   **DONE.** All 9 Tier-1 packages are now split. These two needed two further design calls
   beyond what steps 1–4 established:
   - **The shared-namespace approach (rejected for `csrf`/`whoops`) was the right call here.**
     `Quiote\Telemetry\*` splits into an always-on tier-a (`Trace`, `TraceRegistry`, `SpanKind`,
     no-op handles, `MiddlewareSpanDecorator` — stay in the kernel) and an OTel-SDK-backed tier-b
     (`TelemetryBootstrap`, `OtelSpanHandle`, `OtelMeterHandle`, `ForceSampleSampler`,
     `Psr7HeaderGetter`/`Setter`, `TelemetryPlugin` — moved to `packages/telemetry-otel/`), but
     unlike CSRF/Whoops the cross-references are extensive and functional, not just adjacent:
     `Trace::span()` directly `new OtelSpanHandle(...)`, `TraceRegistry` has a typed
     `?OtelMeterHandle` property, and two *other* core files (`TelemetryMiddleware`, `HttpClient`)
     also instantiate tier-b classes directly. Renaming would have touched `use` imports across
     4+ core files; instead `packages/telemetry-otel/composer.json` declares the *same*
     `Quiote\\Telemetry\\` PSR-4 prefix as the kernel, and Composer's longest-prefix-first
     resolution picks the right directory per class — verified correct for both tiers. Same
     trick for `TelemetryDashboardCommand`, kept under the shared `Quiote\Console\Command\`
     namespace.
   - **A command that must work without ANY app can't use the plugin-command seam even after the
     boundary fix below.** `docs/PLUGIN_EXTRACTION_PLAN.md` originally called moving
     `TelemetryDashboardCommand` to the generic plugin-command-contribution mechanism (the
     `mcp:serve` pattern) "well-supported" — that missed a real regression. `bin/quiote` builds
     `Console\Application` *before* any `Quiote::bootstrap()` call, so a plugin-contributed
     command only appears once a bootstrap has already run in the same process — since fixed for
     the common case (`bin/quiote` now attempts a best-effort pre-bootstrap when an app is
     discoverable, see `Quiote\Console\AppDirResolver` below), `mcp:serve` now shows up in
     `bin/quiote list` without running a command first, same as any other plugin command. But
     `telemetry:dashboard` is a standalone OTLP receiver/TUI process explicitly meant to run via
     `bin/quiote telemetry:dashboard` with **no app directory at all** — the pre-bootstrap can't
     help there (there's nothing to discover), so moving it to the plugin-command seam would still
     silently break bare standalone use. Kept the direct `new TelemetryDashboardCommand()` call in
     `Console\Application`'s constructor, just switched its guard from
     `class_exists(\Symfony\Component\Tui\Tui::class)` to `class_exists(TelemetryDashboardCommand::class)`
     (checking the package's own class, matching the Whoops/CSRF/telemetry-otel pattern of checking
     "is the package installed", not just "is the underlying library present").
   - **Separately, `bin/quiote` itself gained a best-effort pre-bootstrap** (`Quiote\Console\AppDirResolver`,
     shared with `AbstractAppCommand`) so plugin-contributed commands populate before
     `Console\Application` is even constructed: `--app-dir`/`--env`, else `$QUIOTE_APP_DIR`/`$QUIOTE_ENV`,
     else a new `.quiote.json` marker file (`{"app_dir": "...", "env": "..."}`, walked up from
     `$CWD` — the fast, explicit path for a project whose app isn't a directory ancestor of `$CWD`,
     e.g. multiple apps in one repo), else the original upward search for `Config/settings.*`. Any
     resolution/bootstrap failure is swallowed silently so `quiote new` (no app exists yet) and a
     bare `quiote --version` keep working exactly as before. See
     docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md's "Command contribution boundary" for the full writeup.
   - Verification: full `composer test` (1874 tests, unchanged), a scratch bootstrap confirming
     `Trace::span()` returns a real `Quiote\Telemetry\OtelSpanHandle` once telemetry is configured
     and the per-request flush event still reaches `TelemetryBootstrap::flushAfterRequest()`, and
     `bin/quiote list` / `bin/quiote telemetry:dashboard --help` both working with zero bootstrap.

All 9 Tier-1 packages (`mcp`, `ratelimit`, `csrf`, `whoops`, `db-eloquent`, `db-doctrine`,
`db-cycle`, `telemetry-otel`, `telemetry-dashboard`) are now physically split into `packages/*`.
What's left, in order: (a) the actual `split.yml` GitHub Action — nothing is consumable outside
this monorepo yet; (b) Tier 2 (`docs/PLUGIN_EXTRACTION_PLAN.md`'s PHPTAL/XSLT, Gettext, RBAC,
YAML, session-PDO), none of which have had their core seams touched yet.
</content>
