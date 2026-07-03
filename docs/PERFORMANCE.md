# Quiote performance work — hot-path wins + warmup/compile stage

Context: benchmarking Quiote against Symfony/Laravel under FrankenPHP worker
mode (the sibling `framework-benchmark/` project) put Quiote at ~4,669 rq/s vs
Symfony's ~6,100 for an equivalent trivial app. A prior investigation
(`../quiote/QUITE_PERF.md`) concluded the remaining gap was largely
architectural (Symfony compiles its container + router to PHP ahead of time).

This pass did two things:

1. **Re-applied / added per-request hot-path wins** — several cheap wins the
   older repo had shipped were missing or regressed in this rewrite.
2. **Added a Symfony-style warmup/compile stage** — a `cache:warmup` CLI and a
   dumped, self-validating compiled routing matcher.

## Benchmark result

FrankenPHP worker mode, k6 50 VUs / 30s steady, `GET /` on the sample app,
same hardware and sample app for both; two samples each.

| | Baseline (pre-work) | Track A | Track A + compiled matcher |
|---|---|---|---|
| req/s (2 samples) | 4,628 / 4,852 | 4,946 / 5,185 | 5,147 / 4,986 |
| req/s (avg) | ~4,740 | **~5,066** | ~5,067 |
| avg latency | ~8.37 ms | **~7.84 ms** | ~7.8 ms |

**~+7% throughput, ~-6% latency**, all from the hot-path track (Track A). The
third column ran `cache:warmup` at image-build time so the dumped
`CompiledUrlMatcher` (B2) was active (verified baked in) — it makes **no
measurable difference at 4 routes**, which is expected: a compiled matcher pays
off as route count grows, not on a 4-route app. The gap to Symfony (~6,100)
remains partly architectural, as previously noted.

## Track A — per-request hot-path wins (no build step)

- **A1 — Memoize `Log::for()` / `Log::create()`** (`Quiote/Logging/Log.php`).
  Loggers were reallocated on every call (15+/request just to check "is debug
  on?"). Cached by category; cleared in `Log::reset()`. (Ported the tested fix
  that never landed on main.)
- **A2 — Hoist per-request singletons + narrow content negotiation.** Memoized
  `MimeTypeRegistry::allMimeTypes()` (rebuilt the full MIME list per
  content-negotiated request); moved `Negotiator` / `ValidationService` to
  per-worker members. Added `MimeTypeRegistry::negotiableMimeTypes()` — the
  handful of formats actions actually emit (html/json/xml/pdf/csv/xlsx/docx/txt),
  html-first — and made `ContentNegotiationMiddleware` negotiate against that
  instead of ~60 MIME types, plus a fast path that resolves an Accept leading
  with `text/html` or `*/*` straight to html without invoking the negotiator.
  (Left the 9 `new Psr17Factory()` sites alone — empty constructor, not worth
  the churn.)
- **A3 — Skip the request overlay for simple actions**
  (`Quiote/Middleware/ValidationMiddleware.php`). Simple actions paid for the
  full pipeline-request overlay (several immutable clones + an all-attributes
  loop) before the `isSimple` bypass. Now short-circuits after route-param
  promotion (kept — it's the only pre-execution source of `/{id}` params).
  Guarded by `SimpleActionParamPipelineTest` (end-to-end route+query params).
- **A6 — Cache resolved template paths** (`Quiote/View/StreamTemplateLayer.php`).
  `is_readable()` + `expandVariables` ran per candidate per render, and layers
  are rebuilt per render. Process-lifetime static cache keyed on the resolution
  inputs.
- **A7 — Cache the container instantiation plan** (`Quiote/DI/Container.php`).
  `autoWire()` rescanned `getMethods()`/`getAttributes()` on every action
  instantiation (every request). Cache an immutable per-class plan (ctor params
  + `#[Required]` methods, guard evaluated once).
- **A4 — Deferred.** After A3, simple actions never reach `xmlOnlyValidate`, so
  A4 only helps non-simple actions; its impactful part (zero-child early return)
  is unsafe — empty-manager `execute()` drives the strict security param-clear.

## Track B — warmup / compile / dump stage

- **B1 — `quiote cache:warmup`** (`Quiote/Console/Command/CacheWarmupCommand.php`).
  Compiles config ahead of time so a cold worker starts warm. Auto-detects
  backend (APCu vs on-disk `{app_dir}/cache/config`). Dropped two vestigial
  entries from the shared warmup set (`compile.xml` dormant; `routing.xml` has
  no handler here) that only ever errored.
- **B2 — Compiled routing matcher** (`Quiote/Routing/Compiler/CompiledMatcherDumper.php`,
  `Quiote/Routing/Routing.php`). Dumps a Symfony `CompiledUrlMatcher` blob;
  `Routing` prefers it and falls back to the dynamic `UrlMatcher`. The dump file
  is **keyed by a signature of the route definitions**, so a stale/changed table
  finds no file and falls back — a stale dump can never route wrongly. Any load
  failure also falls back; disable via `core.routing.compiled_matcher=false`.
  `cache:warmup --check` drift-checks the artifact for CI. Both degrade
  gracefully when a legacy pattern the dynamic matcher tolerates lazily is
  rejected by Symfony's eager dumper. Proven by `CompiledMatcherParityTest`.
- **B3 — Deferred.** A7 already removed the per-request reflection; dumping the
  plans would only save first-touch reflection per class per worker (a
  cold-start cost amortized to ~zero) and needs a serializable plan format +
  parallel resolver. Matches `DI_MIGRATION_PLAN.md`'s deferral of a compiled
  container. Revisit only if cold-start profiling demands it.

## Re-running the benchmark

The `framework-benchmark/` quiote image copies a prebuilt `vendor/`. To measure
local changes, overlay this working copy's `Quiote/` + `composer.json` into
`vendor/quioteframework/quiote`, `docker compose build quiote`, `up -d`, then
`k6 run -e TARGET=quiote k6/benchmark.js`. To also exercise B2, run
`quiote cache:warmup` inside the image (or add it to the Dockerfile) so the
compiled matcher is present.

All framework changes verified against `composer test` (1,486 tests) and
`composer test:apcu`.
