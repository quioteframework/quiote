# OpenTelemetry Telemetry Plan

## Status (as of this implementation pass)

**Phases 1–8 are implemented** (Phase 7's outbound propagation and Phase 8's
DB/HTTP egress spans deliberately excluded — both scoped out in the original
plan, see below). `Quiote\Telemetry\{Trace, TraceRegistry, SpanHandle,
MeterHandle, NoopSpanHandle, NoopMeterHandle}` (Phase 1), `TelemetryBootstrap`,
`OtelSpanHandle`, `OtelMeterHandle` (Phase 2), `Quiote\Middleware\
TelemetryMiddleware` + `Quiote\Telemetry\SpanKind` (Phase 3), `Quiote\Telemetry\
ForceSampleSampler` (Phase 4), category-based trace filtering in
`TraceRegistry`/`Trace` (Phase 5), route/action/view spans in
`RoutingMiddleware`/`ActionExecutor` plus `SpanHandle::updateName()` (Phase 6),
inbound W3C trace-context propagation + log/trace correlation via
`Quiote\Telemetry\Psr7HeaderGetter` and `SpanHandle::traceId()`/`spanId()`
(Phase 7), and opt-in per-middleware spans via `Quiote\Telemetry\
MiddlewareSpanDecorator` (Phase 8) exist and are tested
(`tests/tests/unit/telemetry/{TelemetryTest,TelemetryBootstrapTest,
TelemetryContainerRegistrationTest,TelemetryMiddlewareTest,
TelemetrySamplingTest,TelemetryCategoryFilteringTest,TelemetryRoutingSpanTest,
TelemetryActionSpanTest,TelemetryPropagationTest,MiddlewareSpanDecoratorTest,
TelemetryPerMiddlewareSpanIntegrationTest}.php`, 123 tests total) **and**
verified against a real OTel Collector receiving live OTLP traffic from an
instrumented sample app (see "End-to-end verification" below — this is the
one part of the telemetry work that unit tests alone can't prove, and it
found a real bug — a `Trace::current()` lifecycle issue — that all 120 unit
tests up to that point had missed; 3 regression tests for it are included in
the count above). That verification is now also a real, repeatable,
Docker-based automated test (`tests/e2e/OtelCollectorE2ETest.php`, 5 tests,
run via `composer test:e2e`) against real FrankenPHP worker mode +
`telemetry.export.mode = batch` — **deliberately excluded from `composer
test`/CI** (`#[Group('e2e')]`, excluded in `tests/config/phpunit.xml` the same
way the APCu tests already are) since it needs Docker and real wall-clock
time to build/start/tear down containers; the 1599-test count for
`composer test` above does not include it. The five
`open-telemetry/*` packages are installed as `require-dev` (not `require` —
still zero-cost/optional in production installs; see "Dependencies" below for
why dev-only was the right call) and also listed under `suggest` for anyone
installing them standalone. `telemetry.enabled` is read via
`Config::get('telemetry.enabled', false)` in `TelemetryBootstrap::
configureFromConfig()`, called once per worker from `Kernel::bootstrap()`
(`Quiote/Runtime/Kernel.php`).

**What Phase 2 actually built, and where it deliberately diverged from the
original sketch below (kept for history; the diffs matter more than the prose
agreeing with itself):**
- `TelemetryBootstrap::configureFromConfig()` builds a real `TracerProvider`
  (`OpenTelemetry\SDK\Trace\TracerProviderBuilder`) + `MeterProvider`
  (`OpenTelemetry\SDK\Metrics\MeterProviderBuilder`) exactly once per process
  (a `self::$configured` guard), gated on `telemetry.enabled` and a
  `class_exists(TracerProviderBuilder::class)` check for the SDK itself. Every
  construction step is wrapped in one `try/catch(\Throwable)`: bad exporter
  config, a missing PSR-18 client, or any other SDK failure logs via
  `Quiote\Logging\Log` and leaves telemetry off, never throwing into the
  request path.
- **The provider singletons live in `TraceRegistry`, not the DI container.**
  The original sketch had `Trace` "resolve the real provider from the
  container on first use" — that would make the static facade depend on an
  active `Context`, breaking the same bootstrap-availability guarantee
  `LogRegistry`'s docblock explicitly calls out (usable before `Kernel::run()`,
  Config/context-free). Instead `TraceRegistry` holds the singletons directly
  (mirroring how `LogRegistry` holds sinks), and
  `Context::registerTelemetryServicesInContainer()` registers DI aliases whose
  factory closures simply return `TraceRegistry::tracerProvider()`/
  `meterProvider()` — one instance either way, DI is a read-only second view
  onto the same registry, not a second source of truth.
- **No `PeriodicExportingMetricReader` exists in the installed SDK version**
  (`open-telemetry/sdk` `^1.14`) — grepped the whole vendor tree to confirm.
  Used `OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader` instead, and
  collect it manually at the same request-boundary flush as the span
  processor (`TelemetryBootstrap::flushAfterRequest()`), rather than a
  background timer. This is arguably a better fit anyway — PHP's
  request/response worker model has no natural background thread to run a
  timer on, so "flush on request boundary" is the correct primitive here, not
  a workaround.
- **Sampler is hardcoded to `AlwaysOnSampler`** — `telemetry.sampling.*` isn't
  read yet; that's Phase 4 below, unchanged from the original plan.
- **OTLP exporter config bridges to OTel's own env vars**
  (`OTEL_EXPORTER_OTLP_ENDPOINT`/`_PROTOCOL`/`_HEADERS`) rather than
  hand-building a `TransportInterface` via `Registry` internals — far less
  surface area to get wrong, and it's exactly how the OTLP SDK expects to be
  configured. `telemetry.otlp.{endpoint,protocol,headers}` settings are
  bridged in `TelemetryBootstrap::applyOtlpEnv()`, called only when
  `telemetry.exporter = otlp`.
- **An unrecognized `telemetry.exporter` value degrades to `none`
  (in-memory) with a warning**, rather than failing the whole provider over a
  config typo — deliberate, and covered by
  `testUnknownExporterValueFallsBackToInMemoryWithWarning`.
- Every real span mutator (`OtelSpanHandle`) and every real metric recording
  (`OtelMeterHandle`) is wrapped in its own `try/catch`, independent of the
  top-level bootstrap try/catch — a bad attribute value or metric input after
  the provider is already up must not crash a single request either. In
  practice the installed SDK version doesn't even throw for a bad attribute
  value (it validates internally and drops the attribute with its own
  warning), but the wrapper is defense-in-depth against SDK versions/exporters
  that might.

**What Phase 3 actually built, and where it diverged:**
- `TelemetryMiddleware` (`#[Middleware(phase: 'bootstrap', priority: 950)]`)
  is registered unconditionally in `MiddlewarePipeline::doBuild()`'s
  `$factories` array (same pattern as `TimingMiddleware`/`TraceMiddleware`) —
  it's always in the default stack, but is a single-`if` pass-through whenever
  `Trace::enabled()` is false, so leaving it in costs nothing when telemetry
  is off. It sits just inside `ErrorHandlingMiddleware` (priority 1000 > 950 —
  higher priority runs more outward), so an uncaught exception hits this
  middleware's own `try/catch/finally` first: the root span is recorded with
  Error status and ended, then the exception is re-thrown for
  `ErrorHandlingMiddleware` (further out) to render the actual error response.
  Two extra backstops cover cases where `TelemetryMiddleware` never got to run
  at all — `ErrorHandlingMiddleware`'s own error callback and the
  `Kernel::run()`-level bootstrap catch (`Quiote/Runtime/Kernel.php`) both also
  call `Trace::current()->recordException($e)->setStatusError(...)`, which is
  a safe no-op in the common case (the span, if any, was already ended and
  detached by `TelemetryMiddleware` by the time these run).
- **Root span naming is deliberately NOT route-templated in this phase** — it
  uses `"{METHOD} {PATH}"` from the raw request (e.g. `"GET /orders/42"`),
  not the low-cardinality route template (`"GET /orders/{id}"}`) the original
  sketch described. Reason: `RoutingMiddleware` sets `module`/`action`/
  `route_name` as PSR-7 request *attributes* on its own request clone deeper
  in the pipeline — per PSR-7 immutability, those mutations are never visible
  back on `TelemetryMiddleware`'s own `$request` reference after
  `$handler->handle()` returns. Renaming the span (and adding `http.route`)
  needs to happen from *inside* `RoutingMiddleware` itself (which can reach
  the still-active root span via `Trace::current()`), which is exactly what
  Phase 5 already does by touching `RoutingMiddleware` directly — so that
  nuance stayed there rather than being forced into Phase 3.
- **Same PSR-7-immutability reasoning excludes `module`/`action`/`http.route`
  from the root span's attributes entirely for now** — only `cache.hit`
  (from `ExecutionState->cacheHit`, which the shared *mutable object* threaded
  through `withAttribute(ExecutionState::class, $exec)` genuinely does
  propagate, the same trick `TimingMiddleware`/`TraceMiddleware` already use)
  made it in.
- **CPU measurement uses `getrusage()` user+system deltas** exactly as
  planned, guarded by `function_exists('getrusage')` (not available on
  Windows) — attributes/metrics are simply omitted, not faked, when
  unavailable.
- **The worker peak-memory reset (`memory_reset_peak_usage()`) runs on every
  request**, not just in worker mode — cheap, and correct in both single-shot
  and worker mode, so no environment branch was needed. Verified by
  `testMemoryPeakIsResetPerRequestNotProcessCumulative`, which inflates the
  peak in request 1 and asserts request 2's peak is measurably lower.
- **`quiote.worker.memory.rss` is a synchronous `recordGauge()` call per
  request, not an OTel *observable* (async, callback-based) gauge.** The
  installed SDK's `MeterInterface::createObservableGauge()` requires wiring a
  callback at meter-creation time, not per-request — a synchronous recording
  taken at the same point as every other headline metric is simpler and
  achieves the same "worker RSS over time" signal without separate instrument
  lifecycle plumbing.
- **Metric/span dimensions are `http.response.status_code` and `cache.hit`
  only** — no `http.route`, for the same PSR-7-immutability reason as above.
  Phase 5 is expected to add route-based dimensioning once it's touching
  `RoutingMiddleware` anyway.
- Confirmed correct end-to-end against the real `MiddlewarePipeline` (not just
  isolated unit tests): a genuine pre-existing exception deep in the sandbox
  test app's routing fixtures (unrelated to telemetry — a malformed route
  pattern Symfony's compiler rejects) was caught by `TelemetryMiddleware`,
  recorded on the span with Error status, and re-thrown; `ErrorHandlingMiddleware`
  still rendered a coherent error response. Exactly the designed failure path.

**What Phase 4 actually built, and where it diverged:**
- `TelemetryBootstrap::buildSampler()` reads `telemetry.sampling.strategy`
  (`parentbased_traceidratio` default, `always_on`, `always_off`, with an
  unrecognized value falling back to `parentbased_traceidratio` + a logged
  warning — same "typo degrades gracefully" pattern as `telemetry.exporter`)
  and `telemetry.sampling.ratio`. The resulting sampler is always wrapped in
  `ForceSampleSampler` — a thin decorator that checks span-creation attributes
  for `quiote.force_sample === true` and short-circuits to
  `SamplingResult::RECORD_AND_SAMPLE` before ever consulting the delegate.
- **Behavior change from Phase 2/3, called out explicitly**: the default
  sampling ratio is now `0.1` (matching the settings sketch earlier in this
  document), replacing Phase 2/3's hardcoded `AlwaysOnSampler`. Anyone who
  enabled `telemetry.enabled` during Phases 2/3 without touching
  `telemetry.sampling.*` will now see ~10% of traces instead of 100% — this is
  the intended effect of Phase 4 existing at all, not an accidental
  regression, but worth stating plainly since it's a behavior change with no
  corresponding settings change required to trigger it. All of Phases 1–3's
  own tests were updated to pin `telemetry.sampling.strategy = always_on`
  explicitly, since they assert on span/metric *content*, not sampling
  behavior — sampling correctness is this file's own job.
- **The force-sample signal travels through span-creation *attributes*, not
  a separate sampler API** — `TelemetryMiddleware::shouldForceSample()` checks
  a PSR-7 `quiote.force_sample` request attribute (settable programmatically)
  or the configured header (`telemetry.sampling.force_header`, default
  `X-Quiote-Trace`, truthy value `1`/`true`/`yes`), and if either is present,
  adds `'quiote.force_sample' => true` to the attributes passed into
  `Trace::span()` — which the OTel API/SDK guarantees are visible to the
  sampler's `shouldSample()` call (span mutations made *after* creation, e.g.
  a later `setAttribute()`, are explicitly NOT visible to samplers, per the
  spec — this is why the signal has to be threaded in at span-creation time,
  not decided some other way).
- **Metrics needed zero code changes to stay unsampled** — `OtelMeterHandle`
  talks to the `MeterProvider`/`Meter` API directly and never touches
  `SamplerInterface` at all, so "metrics are never sampled" was true by
  construction once Phase 2 kept tracing and metrics on separate provider
  objects. Verified anyway, at both the facade level
  (`testRatioZeroStillRecordsAllMetrics`) and the actual integration point
  (`TelemetryMiddlewareTest::testMetricsAreRecordedEvenWhenTheSpanIsDropped`).
- **Parent-based inheritance for a locally sampled span** was verified against
  the real SDK's default `ParentBased` wiring (its local-parent-sampled branch
  defaults to `AlwaysOnSampler` unless overridden) rather than assumed:
  `testChildOfAForceSampledParentIsAlwaysSampledDespiteZeroRatio` force-samples
  a root span, opens a child while the root is still the active span, and
  confirms the child is exported too even at ratio `0.0` — with a contrast
  test (`testChildOfAnUnsampledParentIsNotIndependentlySampled`) proving a
  child does NOT get independently re-rolled against the ratio when its
  parent was dropped.
- **In-process tail-based sampling was not built**, exactly as scoped — the
  plan's stance (head-ratio here, tail sampling in an OTel Collector
  downstream) is unchanged; nothing to implement for a "not doing this" note
  beyond making sure it stays documented as a deliberate absence.

**What Phase 5 actually built, and where it diverged:**
- `TraceRegistry` gained a category-prefix → bool map
  (`setCategoryEnabled()`/`setCategories()`), a `$defaultCategoryEnabled` flag
  (`setDefaultCategoryEnabled()`, default `true`), and `isCategoryEnabled()`
  implementing exactly the cascade algorithm the plan specified: any disabled
  entry on the category's own prefix chain (including itself) wins
  unconditionally; only once nothing on the chain is disabled does
  longest-prefix matching against explicit `true` entries apply. `Trace`
  exposes all three as thin delegating facade methods, mirroring
  `Log::setLevel()`/`setLevels()`.
- **`telemetry.categories.default_enabled` from the original sketch became
  `Trace::setDefaultCategoryEnabled(bool)` — a code call, not a `Config`
  key.** The plan's own example already showed the category *map* itself
  being configured via `Trace::setCategories([...])` in index.php (like
  `Log::setLevels()`), not `settings.xml` — the map obviously can't be a flat
  settings entry (arbitrary category names as keys). Once the map is
  code-configured, having *just* its default fallback be `Config`-driven
  would create a real ordering footgun: `TelemetryBootstrap::
  configureFromConfig()` runs inside `Kernel::bootstrap()`, which is *after*
  whatever index.php code configured categories, so a `Config`-sourced
  default would silently overwrite an explicit `Trace::setDefaultCategoryEnabled()`
  call made earlier. Keeping the whole category subsystem code-configured
  (matching logging's sinks/levels, which are 100% programmatic for the exact
  same bootstrap-ordering reason) avoids that trap entirely.
- **No new `SpanHandle` implementation was needed for "non-recording
  propagation."** The plan's sketch mentioned OTel's `Span::getInvalid()`/a
  dedicated non-recording span type; in practice, `Trace::span()` for a
  filtered-out category returns the exact same shared `NoopSpanHandle` used
  for "telemetry disabled" (Phase 1) — and because a no-op path never calls
  `activate()`, it never pushes anything onto OTel's context stack. Any span
  opened by code running "underneath" a filtered call therefore already sees
  the correct nearest-recorded-ancestor as `Span::getCurrent()`, with zero
  extra plumbing. Verified directly:
  `testSpanAfterAFilteredCallStillParentsOntoNearestRecordedAncestor` asserts
  a real span's `getParentSpanId()` equals the root's actual span ID across a
  filtered-out call in between.
- Category filtering is applied only in `Trace::span()` — `Trace::metrics()`
  takes no category parameter at all (metrics were never categorized to begin
  with), so "metrics are category-agnostic" required no code, just a test
  documenting the invariant (`testMetricsAreRecordedRegardlessOfCategoryFiltering`).

**What Phase 6 actually built, and where it diverged:**
- **Slot/sub-action spans were deliberately dropped from this pass.**
  `SlotDispatcher::dispatch()` is a 634-line method with a security-critical
  recursion guard (`SlotExecutionGuard::enter()`/exit pairing, hard-capped at
  `RECURSION_LIMIT`) and deeply nested nested try/catch blocks with no single
  clean try/finally to attach a span to safely. Wrapping it properly would
  mean restructuring control flow in a method whose entire job is preventing
  runaway recursive rendering — not a change to make as a drive-by inside a
  telemetry pass. Route + action + view spans (the acceptance criterion's
  non-parenthetical core) shipped; the plan's parenthetical "(+ slots)" did
  not. Flagged explicitly rather than silently skipped.
- **`SpanHandle` gained `updateName(string $name): static`**, implemented in
  both `NoopSpanHandle` (no-op) and `OtelSpanHandle` (delegates to the real
  span, `safely()`-wrapped like every other mutator) — needed for
  `RoutingMiddleware` to rename the root span once a route matches, exactly
  as the plan specified.
- **A real bug, caught by the tests, not by inspection**: the first
  implementation had `RoutingMiddleware` call `Trace::current()->updateName(...)`
  *after* already opening its own route-match span via `Trace::span()`.
  `Trace::span()` activates its span immediately, so by that point
  `Trace::current()` returned the route-match span itself, not the outer root
  — the code was renaming its own span to its own new name and leaving the
  actual root untouched. Fixed by capturing `Trace::current()` into a `$root`
  variable *before* opening the route-match span, then renaming that captured
  reference. `testSuccessfulMatchRenamesTheActiveRootSpan` and
  `testSpansRouteFalseStillLeavesRootSpanUnrenamed` both specifically guard
  against this regressing.
- **`http.route` uses the real Symfony path pattern, not just the route
  name** — `RoutingMiddleware::resolveRoutePattern()` fetches the actual
  `Route` object via `$this->routing->exportRoutes()[0]->get($routeName)
  ?->getPath()` (e.g. `/orders/{id}`), falling back to the route name (always
  low-cardinality, if less precise) if that lookup fails for any reason. Both
  `http.route`/`route_name` attributes and the root-span rename use this same
  resolved identity.
- **The rename is gated by the same `telemetry.spans.route` toggle as the
  route-match span itself** — disabling the toggle skips both, not just the
  child span. (The plan groups them under one bullet; a naive implementation
  could have made them independently togglable, which the tests explicitly
  rule out via `testSpansRouteFalseStillLeavesRootSpanUnrenamed`.)
- **`ActionExecutor::execute()` was split into `execute()` (opens/closes the
  action span, catches+records+rethrows) and a new private `doExecute()`**
  holding the original body verbatim (unreindented, to keep the diff
  reviewable and avoid introducing bugs via mechanical reformatting of a
  large, delicate method) — mirroring the same pattern used for the nested
  view span, extracted into `renderView()`. Neither extraction changes
  observable behavior; both exist purely to give each span a clean
  try/catch/finally boundary without restructuring the surrounding logic.
- **The view span is skipped entirely (not even a no-op wrap) when
  `$vn === View::NONE`** — there is nothing to render, matching the plan's
  "a cache hit yields ... no action span" framing (here: no view to render
  yields no view span, an analogous absence rather than an empty span).
- **`telemetry.spans.action` gates both the action span and its nested view
  span** — the plan places both under one bullet; there is no separate
  `telemetry.spans.view` setting.
- Verified against the real `ActionExecutor`/`ViewFactory` path (the sandbox
  app's "Cache" module fixture, the same one `DispatchMiddlewareContextSimpleTest`
  already uses), not hand-rolled Action/View doubles — including a genuine
  parent/child span-ID assertion (`testViewSpanIsANestedChildOfTheActionSpan`)
  and a real thrown-exception path
  (`testExceptionDuringActionIsRecordedOnTheActionSpanAndRethrown`, via a
  `$preInstantiatedAction` override, using `ActionExecutor`'s own test seam for
  exactly this).

**What Phase 7 actually built, and where it diverged:**
- **Inbound extraction**: `TelemetryMiddleware::extractInboundContext()` uses
  `OpenTelemetry\API\Trace\Propagation\TraceContextPropagator` (the standard
  W3C `traceparent`/`tracestate` propagator, ships in `open-telemetry/api`) with
  a new `Quiote\Telemetry\Psr7HeaderGetter` implementing `PropagationGetterInterface`
  — the SDK's default `ArrayAccessGetterSetter` expects array-like access,
  which a PSR-7 `ServerRequestInterface` isn't, so a small bridge was needed.
  The extracted context is activated *before* `Trace::span()` opens the root
  span, so the span builder's default "parent = current context" behavior
  picks up the upstream span with no change to `Trace::span()` itself.
- **Baggage propagation was NOT implemented** — the plan explicitly said
  "optionally Baggage"; out of scope for this pass, no acceptance criterion
  depended on it.
- **A cheap no-op optimization**: `TraceContextPropagator::extract()` returns
  the *exact same* `ContextInterface` instance, unchanged, when there's no
  valid `traceparent` header. `extractInboundContext()` compares identity
  against the pre-extraction context and skips `activate()` entirely in that
  case (returning `null` — nothing to detach later) rather than pushing a
  redundant context frame for the overwhelmingly common "no inbound trace"
  case.
- **Log correlation ended up in `TelemetryMiddleware`, not
  `Context::handle()`** where the original sketch placed it. Reason: at the
  point `Context::handle()` runs `LogContext::enrich(['rid' => ...])`
  (`Quiote/Context.php:596`), the middleware pipeline — and therefore
  `TelemetryMiddleware`, and therefore the root span — hasn't run yet.
  Enriching there would have enriched with an invalid/empty trace ID every
  time. `TelemetryMiddleware` is the earliest point in the actual request
  lifecycle a real span exists, so that's where `correlateLogContext()` lives
  instead — called immediately after `Trace::span()` opens the root span,
  before the downstream handler runs, so the enrichment covers every log line
  for the rest of the request (nothing clears it mid-request; the next
  request's `Context::handle()` → `LogContext::clear()` wipes it, same
  lifecycle as `rid`).
- **`SpanHandle` gained `traceId()`/`spanId()` accessors** (both nullable —
  `null` for a no-op handle or an invalid span context) to make this
  correlation possible without leaking OTel API types into `TelemetryMiddleware`.
  Verified these return real, valid IDs even when the span is dropped by the
  sampler (`testLogCorrelationHoldsEvenForASampledOutSpan`) — trace/span IDs
  are generated before the sampling decision is made, so they exist
  regardless of whether the span is ultimately exported, exactly as the plan
  claims.
- **Outbound propagation was not built**, exactly as scoped in the original
  plan — no HTTP client or `DatabaseManager` egress hooks exist to inject
  `traceparent` into yet.
- Hostile/malformed-input coverage: an invalid `traceparent` (garbage string,
  all-zero trace ID, empty header value) never crashes the request — the
  propagator's own `extract()` already degrades to "start a fresh trace"
  internally, and `extractInboundContext()` additionally wraps the whole
  thing in `try/catch` as defense-in-depth, consistent with every other
  telemetry call site's "never crash the request" guarantee. Also verified: a
  remote parent explicitly marked "not sampled" (`traceparent` flags byte
  `00`) is respected via `ParentBased`'s remote-parent-not-sampled branch,
  independent of our own configured ratio.

**What Phase 8 actually built, and where it diverged:**
- **Per-middleware spans**: `Quiote\Telemetry\MiddlewareSpanDecorator`
  implements `MiddlewareInterface`, wraps an inner middleware, and opens a
  `Quiote.Middleware`-category span named by the exact string
  `MiddlewarePipeline` already uses for its `debugStack()` labels (the FQCN)
  — so this reproduces `TraceMiddleware`'s existing flat name list as real,
  nested spans, exactly as specified. Wired into both of
  `MiddlewarePipeline`'s construction paths: the `$construct` closure inside
  `doBuild()` (the default core stack) and `insertRegistered()` (app
  middleware spliced in via `MiddlewareCatalog::register()`), so an app's own
  registered middleware gets spans too, not just the framework's built-ins.
- **The opt-in check happens once per pipeline build, not once per
  middleware.** `$spanEachMiddleware = Trace::enabled() &&
  Config::get('telemetry.spans.middleware', false)` is computed a single time
  at the top of `doBuild()`/`insertRegistered()`, not re-read inside the
  `$construct` closure for every middleware — safe because
  `MiddlewarePipeline`'s stack (and thus `doBuild()`) only runs once per
  worker and is cached (`$this->built`), and telemetry itself is configured
  once at worker startup before any request runs, so `Trace::enabled()`
  can't change value mid-worker either. When the setting is off, `doBuild()`
  never even allocates a `MiddlewareSpanDecorator` — zero cost, not just an
  unused wrapper.
- **An unavoidable, worth-noting nesting quirk**: because `ErrorHandlingMiddleware`
  is itself wrapped (it's index 0 in the stack, outermost), its decorator's
  span opens *before* `TelemetryMiddleware` ever runs — meaning with
  `telemetry.spans.middleware` on, the actual trace root becomes
  `ErrorHandlingMiddleware`'s wrapper span, not the semantic
  `"{METHOD} {route}"` HTTP root span (which becomes a *child*, opened once
  `TelemetryMiddleware`'s own code runs inside its own wrapper span). This is
  expected and harmless for a debugging feature explicitly framed as
  high-cardinality/opt-in, but worth knowing before turning it on: the root
  span you see becomes a framework-internal label, not the request identity,
  for as long as this toggle is on. Verified directly —
  `testRouteMatchSpanNestsUnderItsOwnMiddlewareSpan` confirms the "match"
  span (opened *inside* `RoutingMiddleware`'s own code) correctly parents
  onto `RoutingMiddleware`'s wrapper span, proving this is a genuine nested
  tree and not a flat list dressed up as spans.
- **Database + outbound HTTP CLIENT-kind spans were NOT implemented** — the
  plan itself said this "needs seams in the DB and HTTP-client layers;
  deferred," and that's still true: `Database::getConnection()` returns the
  raw driver connection directly (a bare `\PDO` for `PdoDatabase`; something
  else entirely for the Doctrine/Propel subclasses), with no central
  query-execution method anywhere in the framework to instrument — callers
  and ORMs call `query()`/`exec()`/`prepare()` straight on that connection.
  Instrumenting this uniformly would mean building a genuinely new PDO
  decorator/proxy layer (and equivalent shims for the other drivers) — a real
  feature in its own right, not an instrumentation pass over an existing
  seam, and out of scope here. The framework also has no outbound HTTP client
  of its own to hook (it's a server-side framework; nothing in it makes
  egress HTTP calls), so there is currently nothing to instrument on that
  side regardless.

### End-to-end verification

Beyond the unit-test suite (which proves the code paths are correct, using an
in-memory exporter), this pass was verified against a **real OTel Collector**
receiving live OTLP traffic from the actual sample app under `samples/app`,
served by PHP's built-in webserver, with real HTTP requests fired at it and
the Collector's own debug-exporter output inspected to confirm spans and
metrics genuinely arrived, with real trace IDs, timing, resource attributes,
and correct span nesting. This is the one part of "does the telemetry
actually work" that an in-memory exporter cannot prove — the in-memory
exporter validates that `Quiote\Telemetry` code produces correct span/metric
objects, but says nothing about whether OTLP serialization, transport, and a
real collector's ingestion actually agree on the wire format. See
[docs/OPENTELEMETRY_E2E_VERIFICATION.md](OPENTELEMETRY_E2E_VERIFICATION.md)
for the full setup, commands, and captured output.

Phase 9 is not started. What else exists beyond Phases 1–8 and shapes the
remaining design:

- **Measurement precedents already in the pipeline** we extend rather than
  reinvent: `TimingMiddleware` (`Quiote/Middleware/TimingMiddleware.php:18`,
  writes `total_ms` into `ExecutionState->metrics`), `TraceMiddleware`
  (`Quiote/Middleware/TraceMiddleware.php:18`, records executed-middleware
  names into `metrics['trace']`), and `ExecutionTimeMiddleware`
  (`Quiote/Middleware/ExecutionTimeMiddleware.php:18`). All three currently
  *measure but don't emit* (`emitHeader=false`). They are the seam.
- **A per-request metrics carrier already exists**: `ExecutionState->metrics`
  (`Quiote/Execution/ExecutionState.php:28`), passed pipeline-wide via the
  `ExecutionState::class` request attribute.
- **A correlation ID + ambient log scope already exists**: `Context::handle()`
  generates a per-request `rid` and calls
  `LogContext::enrich(['rid' => ...])` (`Quiote/Context.php:581-596`). This is
  where trace-id ↔ log correlation attaches.
- **`#[Middleware(phase, priority, before, after, enabled)]` ordering** is live
  (commit `1d4ad4f7`); the phase order is `bootstrap, pre_routing, pre, routing,
  before_action, action, after_action, finalize`
  (`Quiote/Middleware/Compiler/MiddlewarePhase.php:16`). A telemetry middleware
  slots in by attribute like every other.
- **A DI container with singleton scope** exists
  (`Quiote/DI/Container.php`, `SCOPE_SINGLETON`), wired in
  `Context::registerCoreServicesInContainer()` (`Quiote/Context.php:312-331`).
  This is where a long-lived provider registers — **not** in
  `FactoryConfigHandler::getFactoryDefinitions()` (heavyweight, startup-ordered).
- **The logging subsystem is the template for this whole feature**: a static
  facade (`Quiote\Logging\Log`) over a process-global, worker-lifetime registry
  (`LogRegistry`), configured once in `index.php` before `Kernel::run()`, with a
  cheap `isEnabled(Level)` gate guarding every call site. Telemetry mirrors this
  exactly (`Quiote\Telemetry\Trace` facade + `Trace::enabled()` gate).
- **A worker-flush seam exists**: `WorkerManager` supports an
  `after_request_callback` (`Quiote/Util/WorkerManager.php:260`) and the Kernel
  wires a per-request `$reset` closure (`Quiote/Runtime/Kernel.php:98-104`).

---

## Background

Quiote runs either single-shot (`SingleRequestAdapter`) or as a persistent
FrankenPHP worker (`FrankenPhpWorkerAdapter`, `Quiote/Runtime/Kernel.php:130`).
The worker case is the one that matters most for the stated goals — a long-lived
process where **memory growth across requests** and **per-request CPU** are the
things that actually bite in production. The design must therefore treat the
provider as a worker-lifetime singleton (built once) while treating spans and
resource measurements as strictly per-request, with a hard flush/scope-reset
boundary between requests so nothing leaks from request N into request N+1.

The three headline signals the user cares about — **wall time, memory, CPU
between request start and response end** — are captured as OTel *metrics*
(histograms/gauges) *and* mirrored as attributes on the root request span, from
a single measurement taken at one place. Tracing execution *through* the
framework is captured as a span tree beneath that root.

### Request → response lifecycle (span/measurement seams)

```
FrankenPhpWorkerAdapter::run loop        Quiote/Runtime/Worker/FrankenPhpWorkerAdapter.php:17
  Kernel $handle closure                 Quiote/Runtime/Kernel.php:61   ← outermost per-request boundary
    Context::handle()                    Quiote/Context.php:574         ← rid + traceparent extract; log correlation
      MiddlewarePipeline::handle()       Quiote/Middleware/MiddlewarePipeline.php:29
        ErrorHandlingMiddleware          bootstrap/1000                 ← exception → span error status
        [TelemetryMiddleware]            bootstrap/~950 (NEW)           ← ROOT SERVER span opens; resource snapshot
        RoutingMiddleware                routing/0                      ← route-match span; route name for span name
        Security/Csrf/Validation/Slot    before_action
        DispatchMiddleware               action                         ← action + view execution
          ActionExecutor::execute()      Quiote/Execution/ActionExecutor.php:147  ← action span
            ActionResolver::execute()    Quiote/Execution/ActionResolver.php:21   ← controller/action invoked
            view render                  Quiote/Execution/ActionExecutor.php:220-237 ← view-render span
        ExecutionTimeMiddleware          finalize/-10
      HttpEmitter::emit()                Quiote/Runtime/HttpEmitter.php:8  ← response egress (status/size known)
```

The **root span** is opened by a new `TelemetryMiddleware` in the `bootstrap`
phase rather than in `Kernel.php` or `Context::handle()`, because (a) it gives a
span whose lifetime is the whole pipeline, (b) it can be enabled/disabled/ordered
by the same `#[Middleware]` machinery as everything else, and (c) it can read the
final response's status/size on the way back out. The one thing it does *not*
naturally see is exceptions — `ErrorHandlingMiddleware` (priority 1000) sits
above it and converts throwables to error responses before they bubble up. See
Phase 3 for how the error boundary records exceptions onto the active span.

---

## Dependencies

OpenTelemetry PHP is modular. Add as **optional** (`suggest` + soft runtime
detection), so a project that never turns telemetry on pays nothing and the
framework does not hard-depend on the SDK:

- `open-telemetry/api` — the API surface instrumentation codes against.
- `open-telemetry/sdk` — providers, processors, samplers, resource.
- `open-telemetry/sem-conv` — semantic-convention attribute constants
  (`http.request.method`, `http.route`, `url.path`, …).
- `open-telemetry/exporter-otlp` — OTLP exporter (traces + metrics).
- `open-telemetry/context` — context propagation (pulled transitively).

The OTLP/HTTP exporter needs a PSR-18 client + PSR-17 factories. **Nyholm PSR-17
is already a hard dependency** (`nyholm/psr7`, `nyholm/psr7-server`); we need a
PSR-18 client — `symfony/http-client` (Symfony 8 is already pervasive here) via
`php-http/discovery`, or document `ext-curl` + a thin PSR-18 shim. OTLP/gRPC
would additionally need `ext-grpc` + `google/protobuf`; keep gRPC out of the
default and document it as opt-in.

**Explicitly not** using the zero-code `ext-opentelemetry` auto-instrumentation
PECL extension as the primary path: a framework should ship first-party manual
instrumentation it controls, not rely on a runtime extension being present. (If
the extension *is* present it composes fine — our spans nest under whatever it
opens — but we do not require it.)

`composer.json`: add the five packages under `suggest` with a one-line "install
these to enable `telemetry.*`" note, mirroring how `ext-xsl` / `ext-xdebug` are
already handled. A follow-up may promote them to `require` behind a
`quiote/telemetry` split package; out of scope here.

**Implementation note (Phase 2):** the five packages are additionally declared
under `require-dev` (not `require`) — this is *not* a hard runtime dependency:
`composer install` without `--dev` (a normal production install) never pulls
them, and every call site that touches an OTel class is `class_exists()`- or
try/catch-guarded (see Phase 2 below). Dev-only installation exists purely so
the test suite exercises the real SDK (real `TracerProvider`, real in-memory
exporters) instead of hand-rolled fakes standing in for an API this project
doesn't control. Deliberately did **not** add a PSR-18 client implementation
(no `symfony/http-client`/guzzle) even to `require-dev` — its absence is itself
load-bearing: it's what makes the "OTLP exporter requested but no HTTP client
installed" failure path (`testOtlpWithoutPsr18ClientFallsBackToDisabled`) a
real, reproducible test rather than a mocked one.

---

## The `telemetry.*` settings family

Declared like any setting family — a `<settings prefix="telemetry.">` block in
the app's `settings.xml`, or the equivalent flat keys in `settings.php`
(both work; `SettingConfigHandler` implements `IArrayConfigHandler`). Nested
config uses `<ae:parameter>` children, which `SettingConfigHandler` turns into a
nested array (`Quiote/Config/SettingConfigHandler.php:80-81`). The existing
settings XSD is permissive (`setting/@name` is any string) — **no schema change
needed**.

Read at runtime with `Config::get('telemetry.enabled', false)` etc., exactly
like `core.use_translation` / `core.debug` are read today. Every key has a safe
code-level default via `Config::get`'s `$default`, so an app that declares
nothing gets telemetry fully off.

```php
// settings.php equivalent (flat, dot-keyed — what SettingConfigHandler compiles to)
return [
    // master gate — everything below is dead unless this is true
    'telemetry.enabled'            => false,

    // signal toggles (independent; metrics are cheap, traces cost more)
    'telemetry.traces.enabled'     => true,
    'telemetry.metrics.enabled'    => true,

    // resource identity (falls back to core.app_name if unset)
    'telemetry.service.name'       => 'quiote-app',
    'telemetry.service.namespace'  => null,
    'telemetry.resource'           => ['deployment.environment' => '%core.environment%'],

    // exporter
    'telemetry.exporter'           => 'otlp',        // otlp | console | none(in-memory)
    'telemetry.otlp.endpoint'      => 'http://localhost:4318',
    'telemetry.otlp.protocol'      => 'http/protobuf', // http/protobuf | grpc
    'telemetry.otlp.headers'       => [],              // e.g. auth
    'telemetry.export.mode'        => 'batch',         // batch | simple (single-shot uses simple)

    // sampling — traces only; metrics are never sampled
    'telemetry.sampling.strategy'  => 'parentbased_traceidratio', // + always_on | always_off
    'telemetry.sampling.ratio'     => 0.1,             // 0.0–1.0

    // span-tree depth — coarse → fine, each level is more overhead
    'telemetry.spans.pipeline'     => true,   // root request span (always on if traces on)
    'telemetry.spans.route'        => true,   // route-match span
    'telemetry.spans.action'       => true,   // action + view spans
    'telemetry.spans.middleware'   => false,  // per-middleware child spans (high cardinality)

    // category filtering default (the per-category on/off map itself is code-
    // configured via Trace::setCategories(), mirroring Log::setLevels() —
    // see Phase 5 — not declared here as flat settings keys)
    'telemetry.categories.default_enabled' => true,

    // the headline resource metrics
    'telemetry.resource_metrics.cpu'    => true,  // getrusage user+sys CPU per request
    'telemetry.resource_metrics.memory' => true,  // peak memory + worker growth
];
```

```xml
<!-- settings.xml equivalent -->
<settings prefix="telemetry.">
    <setting name="enabled">false</setting>
    <setting name="sampling">
        <ae:parameter name="strategy">parentbased_traceidratio</ae:parameter>
        <ae:parameter name="ratio">0.1</ae:parameter>
    </setting>
    <!-- ... -->
</settings>
```

---

## Phase 1 — Settings family, dependency wiring, and a no-op skeleton

**Implemented.**

Goal: everything scaffolded, **zero behavior change**, telemetry off.

- Add the OTel packages to `composer.json` `suggest`.
- Add the `telemetry.*` defaults to the sample app (`samples/app/Config/`) and
  document the family (see Phase 8).
- Create the `Quiote\Telemetry` namespace with:
  - `Trace` — a static facade mirroring `Quiote\Logging\Log`: `Trace::enabled()`,
    `Trace::span(string $name, array $attrs = []): SpanHandle`,
    `Trace::current(): SpanHandle`, `Trace::metrics(): MeterHandle`. When
    telemetry is off, every method returns a shared **no-op** `SpanHandle`/
    `MeterHandle` and `enabled()` returns false — instrumentation call sites are
    always safe and effectively free.
  - `NullTracer`/`NoopSpanHandle` — the disabled-state implementations, so no
    call site ever needs a null check beyond the cheap `enabled()` gate.
- **No SDK is touched in this phase** — the facade delegates to no-ops. This
  isolates "instrumentation points compile and run harmlessly" from "the SDK is
  correctly wired," so the two can be reviewed independently.

Acceptance: full test suite green with the feature absent; `Trace::enabled()`
returns false everywhere; no new runtime cost on the hot path.

---

## Phase 2 — Provider bootstrap, DI singleton, worker lifecycle

**Implemented** — see "Status" at the top of this document for what shipped
and where it diverged from the sketch below (kept for history).

Goal: with `telemetry.enabled = true`, a real `TracerProvider` + `MeterProvider`
exist as **worker-lifetime singletons**, and are flushed/reset cleanly between
requests.

- `TelemetryBootstrap::configure()` — builds the SDK once from `telemetry.*`:
  - `ResourceInfo` from `telemetry.service.*` + `telemetry.resource` (default
    `service.name` to `core.app_name`).
  - Span processor: `BatchSpanProcessor` when `telemetry.export.mode = batch`
    (worker), `SimpleSpanProcessor` for `simple` (single-shot). Exporter per
    `telemetry.exporter` (`otlp` → OTLP exporter with the configured
    endpoint/protocol/headers; `console` → console exporter; `none` → in-memory,
    used by tests).
  - `MeterProvider` with a `PeriodicExportingMetricReader` (worker) or a
    force-flush-at-shutdown reader (single-shot).
- **Registration**: in `Context::registerCoreServicesInContainer()`
  (`Quiote/Context.php:312-331`), gated on `Config::get('telemetry.enabled')`:
  register the providers as `Container::SCOPE_SINGLETON` closure factories and
  `alias()` the OTel interfaces to them. This mirrors the intended
  `LoggerFactoryInterface` injection pattern. **Do not** add to
  `FactoryConfigHandler`.
- **Worker lifecycle** — the critical correctness boundary:
  - Provider built **once** per worker (singleton), never per request.
  - Register an `after_request_callback` via `WorkerManager`
    (`Quiote/Util/WorkerManager.php:260`) — invoked from the Kernel `$reset`
    closure (`Quiote/Runtime/Kernel.php:98-104`) — that force-flushes the batch
    processor / detaches any lingering span scope so request N's telemetry is
    exported and request N+1 starts clean. This piggybacks on the existing
    between-request reset, the same place `LogContext` is cleared.
  - Single-shot mode: register `TracerProvider::shutdown()` /
    `MeterProvider::shutdown()` on `register_shutdown_function` (after
    `HttpEmitter::emit()`), matching how single-shot has no persistent loop.
- Facade `Trace` now resolves the real provider from the container on first use
  (lazily, cached for the worker lifetime).

Acceptance: worker serves N requests, exactly one provider instance exists,
spans/metrics from each request are flushed at that request's boundary, and
memory does not grow unboundedly from retained spans (assert via the in-memory
exporter + a soak test in the sandbox app).

---

## Phase 3 — Root request span + the headline resource metrics (time / memory / CPU)

**Implemented** — see "Status" at the top of this document for what shipped
and where it diverged from the sketch below (kept for history).

This is the core of the user's ask. A single `TelemetryMiddleware`
(`#[Middleware(phase: 'bootstrap', priority: 950)]`, i.e. just inside
`ErrorHandlingMiddleware`) owns the root span and the one resource measurement.

**On the way in** (before `$handler->handle()`):
- Extract W3C `traceparent`/`tracestate` from request headers → parent context
  (Phase 6 covers propagation in full; the extract belongs here).
- Open a `SERVER`-kind root span. Provisional name `HTTP {method}`; renamed to
  the low-cardinality route template once `RoutingMiddleware` has run
  (`http.route` attribute → `{method} {route}`), read from the `route_name`/
  `ActionDescriptor` request attributes (`Quiote/Middleware/RoutingMiddleware.php:45-59`).
- Snapshot the start measurements into `ExecutionState->metrics`:
  - **wall start**: prefer `$_SERVER['REQUEST_TIME_FLOAT']` (true request arrival,
    before framework bootstrap) so the histogram reflects real user-perceived
    time, not just pipeline time; fall back to `microtime(true)`.
  - **CPU start**: `getrusage()` → capture `ru_utime` + `ru_stime`
    (user + system CPU seconds). In a worker these are cumulative for the
    process, so we store the snapshot and take a **delta** at the end — that
    delta is this request's CPU.
  - **memory start**: `memory_get_usage(true)` and reset the peak baseline
    understanding (`memory_get_peak_usage(true)` is process-cumulative in a
    worker; see below).

**On the way out** (after `$handler->handle()` returns, in a `finally`):
- Compute deltas: `duration_ms`, `cpu_user_ms`, `cpu_system_ms`,
  `cpu_total_ms`; capture `memory_peak_bytes = memory_get_peak_usage(true)` and
  `memory_delta_bytes`.
- Set them as attributes on the root span, alongside `http.response.status_code`
  and response body size (both known from the returned response — the same
  values `HttpEmitter` will emit), and framework dimensions from
  `ExecutionState` (`module`, `action`, `outputType`, `cacheHit`).
- Record the OTel **metrics** (traces may be sampled; **these are not**):
  - `http.server.request.duration` — histogram, seconds (sem-conv standard).
  - `quiote.request.cpu.time` — histogram, seconds, split by `cpu.mode`
    (`user`/`system`).
  - `quiote.request.memory.peak` — histogram, bytes.
  - `quiote.worker.memory.rss` — observable gauge sampled per request
    (`memory_get_usage(true)`), the primary **leak-detection** signal across a
    worker's life.
  - `http.server.active_requests` / request count — counter dimensioned by
    `http.route`, `http.response.status_code`, `cache.hit`.
  Metric dimensions are kept low-cardinality (route template, not raw path;
  status code, not full URL).
- Close the span.

**Worker peak-memory nuance** (worth calling out because it is a real trap):
`memory_get_peak_usage()` is monotonic for the process lifetime, so in a worker
it does not reset between requests and would report the all-time peak, not this
request's. `memory_reset_peak_usage()` (PHP 8.2+; we require 8.5) is called at
request start in worker mode so the per-request peak is meaningful. The all-time
process footprint is captured separately by the `quiote.worker.memory.rss`
gauge.

**Exception recording**: because `ErrorHandlingMiddleware` (priority 1000) sits
above `TelemetryMiddleware` and swallows throwables into error responses, the
telemetry middleware sees a normal response, not an exception. Two hooks close
this:
1. `ErrorHandlingMiddleware`'s existing error callback
   (`Quiote/Middleware/MiddlewarePipeline.php:84-88`) additionally calls
   `Trace::current()->recordException($e)->setStatus(Error)` — the active span
   is the root span, so the exception lands correctly even though it never
   propagates up to `TelemetryMiddleware`.
2. The Kernel-level catch (`Quiote/Runtime/Kernel.php:66-94`), for
   bootstrap-phase failures that occur before/around the pipeline, records onto
   whatever span is active (or a minimal error span) so pre-pipeline crashes are
   still visible.
Additionally, any 5xx status observed on the way out sets the span status to
Error as a backstop.

Acceptance: a request produces one root span carrying accurate
duration/CPU/memory/status attributes, and the corresponding metrics land in the
in-memory/console exporter; CPU and peak-memory deltas are per-request-correct in
both single-shot and worker mode (verified against a deliberately CPU- and
memory-heavy sandbox action).

---

## Phase 4 — Sampling

**Implemented** — see "Status" at the top of this document for what shipped
and where it diverged from the sketch below (kept for history).

- **Traces are head-sampled**; metrics are never sampled (aggregates must be
  complete). Default sampler: `ParentBased(TraceIdRatioBased(ratio))` from
  `telemetry.sampling.ratio` — honors an upstream sampling decision
  (`traceparent`) and applies the ratio only to locally-initiated roots.
  `always_on` / `always_off` selectable via `telemetry.sampling.strategy`.
- **Force-sample escape hatch**: a custom sampler wrapper that always samples
  when a debug signal is present — a configured header (e.g.
  `X-Quiote-Trace: 1`) or a `quiote.force_sample` request attribute. This makes
  "trace this one request in prod" possible without flipping the global ratio.
  Head-based, so it is a pre-decision, not outcome-based.
- **On tail-based / error-and-slow sampling** (keep 100% of failed or slow
  requests, sample the rest): genuine tail sampling needs a collector — it
  cannot be decided in-process at span start when the outcome isn't known yet.
  The pragmatic in-process approximation, documented but *not* on by default:
  run spans in "record-only" and use a `SpanProcessor` that drops non-error,
  non-slow spans at `onEnd`. This is deferred; the recommended production shape
  is head-ratio sampling here + tail sampling in an OTel Collector downstream.
  The plan explicitly notes this rather than pretending in-process tail sampling
  is free.

Acceptance: with `ratio=0.0` no spans export but all metrics still do; with the
force-sample header a single request is fully traced regardless of ratio; a
child of a sampled upstream trace is always sampled.

---

## Phase 5 — Category-based trace filtering

**Implemented** — see "Status" at the top of this document for what shipped
and where it diverged from the sketch below (kept for history).

Sampling (Phase 4) is a probabilistic, trace-wide knob. This is a second,
orthogonal filter axis: a deterministic, per-category on/off switch — "trace
`Quiote`, but not `Quiote.Routing`; trace `App`, but not
`App.NamespaceWeDontCareAbout`" — for silencing specific noisy or uninteresting
subtrees regardless of the sampling decision.

- Every span is opened through the `Trace` facade with a dot-namespaced
  **category**, the same convention `Log::create('Quiote.Routing')` /
  `Log::for($this)` already use: `Trace::span('Quiote.Routing', 'match', $attrs)`.
- `Quiote\Telemetry\TraceRegistry` mirrors `LogRegistry`'s storage
  (`Quiote/Logging/LogRegistry.php`) — a category-prefix → bool map, configured
  once at worker startup in `index.php` alongside `Log::setLevels(...)`:
  ```php
  Trace::setCategories([
      'Quiote' => true,
      'Quiote.Routing' => false,
      'App' => true,
      'App.NamespaceWeDontCareAbout' => false,
  ]);
  ```
- **Deliberate divergence from `LogRegistry::resolveLevel()`'s semantics.**
  Logging resolves by *longest*-matching prefix, so a more specific child
  setting overrides its parent. Trace category filtering instead makes a
  disabled ancestor an **unconditional, non-overridable cascade**: disabling
  `Quiote.Validation` disables every span under it — including
  `Quiote.Validation.Rules` — even if `Quiote.Validation.Rules` has its own
  explicit `true` entry. This is what makes it a real kill switch: turning off
  a noisy subtree is one line, not an exercise in enumerating every leaf
  category under it.
  - `TraceRegistry::isEnabled(string $category): bool` algorithm:
    1. Walk the category's own prefix chain (`Quiote.Validation.Rules` →
       `Quiote.Validation` → `Quiote`); if **any** entry on that chain,
       including the category itself, is explicitly `false`, return `false`
       immediately — no other setting can override this.
    2. Otherwise, resolve via longest-prefix match against explicit `true`
       entries (same mechanics as `LogRegistry::resolveLevel()`), falling back
       to a configurable default (`telemetry.categories.default_enabled`,
       default `true`).
- **Non-recording propagation, not a broken tree.** A disabled category must
  not sever the trace — if `Quiote.Validation` is off but a call beneath it
  opens a `Quiote.Validation.Rules.Custom` span (impossible to disable
  individually per the cascade above, but the general mechanism matters for any
  span whose immediate parent was skipped), the skipped span still pushes a
  **non-recording span** (OTel's `Span::getInvalid()`/no-op recording span) that
  carries the current trace/span context forward without emitting a span of its
  own. Children then correctly reparent onto the nearest enabled ancestor
  instead of onto nothing. `Trace::span()` returns the same no-op `SpanHandle`
  used when telemetry is globally disabled (Phase 1) — call sites never branch
  on category state themselves.
- Applies to spans only; the headline resource metrics (Phase 3) and other
  OTel metrics are category-agnostic and are never filtered this way — a
  disabled trace category still contributes to `http.server.request.duration`
  etc., since those aggregates aren't about narrative detail.

Acceptance: with `Quiote.Validation => false`, no span named under that category
(at any depth) is recorded, but its children's children still parent correctly
onto the nearest enabled ancestor span; an explicit `true` on a descendant of a
disabled category has no effect (proving the cascade, not longest-prefix,
governs); metrics are unaffected by category state.

---

## Phase 6 — Span tree through the framework (route / action / view)

**Implemented, except slot/sub-action spans (deliberately deferred)** — see
"Status" at the top of this document for what shipped and where it diverged
from the sketch below (kept for history).

Child spans under the root, each gated by its `telemetry.spans.*` toggle so
depth (and overhead) is configurable:

- **Route-match span** (`telemetry.spans.route`): wrap
  `RoutingMiddleware::process()` (`Quiote/Middleware/RoutingMiddleware.php:23`).
  Attributes: matched `http.route`, `route_name`, and a 404/405 outcome. This is
  also where the root span gets its final name.
- **Action span** (`telemetry.spans.action`): open around
  `ActionExecutor::execute()` (`Quiote/Execution/ActionExecutor.php:147`) with
  `module`/`action`/`method`/`outputType` attributes; a nested **view-render
  span** around the view resolution + render block
  (`ActionExecutor.php:220-237`). A `cache.hit` attribute marks
  cache-served responses (`DispatchMiddleware` short-circuits execution on hit —
  the span reflects that no action ran).
- **Slot/sub-action spans**: nested renderables (`SlotDispatcher`,
  `SlotStack`) each become a child span when action spans are on — this is where
  "tracing execution through the framework" gets genuinely useful for pages
  built from many slots.

Instrumentation uses the `Trace::span()` facade (a scope-guard object whose
`__destruct`/`finally` closes the span), so a site is a two-line wrap and is a
no-op when disabled. Prefer wrapping at the executor/middleware boundaries over
sprinkling calls deep inside hot loops.

Acceptance: a normal request yields root → route → action → view (+ slots); a
cache hit yields root → route with `cache.hit=true` and no action span.

---

## Phase 7 — Context propagation and log correlation

**Implemented, except outbound propagation (deliberately excluded, as scoped
below)** — see "Status" at the top of this document for what shipped and
where it diverged from the sketch below (kept for history).

- **Inbound**: extract `traceparent`/`tracestate` (W3C `TraceContext`
  propagator, optionally Baggage) in `TelemetryMiddleware` so a Quiote request
  joins an upstream distributed trace.
- **Log ↔ trace correlation** (high value, low cost): in `Context::handle()`
  where `LogContext::enrich(['rid' => ...])` already runs
  (`Quiote/Context.php:596`), also enrich with `trace_id`/`span_id` from the
  active span. Every log line then carries the trace id, so logs and traces are
  cross-navigable — and this works even for sampled-out traces (the ids still
  exist). This is the single best correlation win and should not wait.
- **Outbound** (future, noted not built): inject `traceparent` into outbound
  HTTP client calls and DB spans so downstream services continue the trace.
  Needs hooks in whatever HTTP client / `DatabaseManager` layer makes egress
  calls; scoped out here.

Acceptance: an inbound `traceparent` produces spans whose parent is the upstream
span; every log line during a request carries the same `trace_id` as that
request's root span.

---

## Phase 8 — Optional per-middleware spans and egress instrumentation (future)

**Per-middleware spans implemented; DB/HTTP egress spans deliberately
excluded** (no seam exists yet, exactly as this plan already anticipated) —
see "Status" at the top of this document for what shipped and where it
diverged from the sketch below (kept for history).

- **Per-middleware spans** (`telemetry.spans.middleware`, default off): wrap each
  middleware as the stack is assembled in `MiddlewarePipeline::doBuild()` — most
  cleanly via a Relay queue decorator or by wrapping the `$construct` closure
  (`Quiote/Middleware/MiddlewarePipeline.php:73`) so every middleware gets a
  child span named by its FQCN. This reproduces, as real spans, what
  `TraceMiddleware` records as a flat name list today. High cardinality/overhead
  — opt-in only.
- **Database + outbound HTTP spans**: `CLIENT`-kind spans around DB queries and
  HTTP egress, with `traceparent` injection (ties into Phase 6 outbound). Needs
  seams in the DB and HTTP-client layers; deferred.

---

## Phase 9 — Documentation and tests

- **Tests** (use `telemetry.exporter = none` → in-memory exporter):
  - Facade no-op behavior when disabled (Phase 1).
  - Single provider instance across a simulated worker loop; flush at request
    boundary; no span retention growth (Phase 2).
  - Root span attributes + duration/CPU/memory metrics correctness in both
    single-shot and worker mode, including the `memory_reset_peak_usage` per-
    request-peak behavior (Phase 3).
  - Sampler decisions: ratio 0/1, parent-based inheritance, force-sample header
    (Phase 4).
  - Category cascade: disabled ancestor wins over an explicitly-enabled
    descendant; non-recording spans preserve parent/child linkage; metrics
    unaffected by category state (Phase 5).
  - Span tree shape for normal vs cache-hit requests (Phase 6).
  - `traceparent` extraction + `trace_id` in `LogContext` (Phase 7).
  - Overhead guard: assert the disabled hot path adds no SDK calls.
- **Docs**: a `telemetry.*` reference (mirroring
  `docs/CONFIGURATION_SETTINGS.md`), a "wire it to an OTel Collector / Jaeger /
  Prometheus" quickstart, and a note on the recommended production topology
  (head-ratio in-process + Collector tail sampling).

---

## What this is NOT

- **Not on by default.** `telemetry.enabled` is false; a project that declares no
  `telemetry.*` settings and installs no OTel packages sees zero change and zero
  cost.
- **Not a hard dependency.** OTel packages stay in `suggest`; the framework runs
  identically without them (facade no-ops).
- **Not relying on the `ext-opentelemetry` auto-instrumentation extension.**
  First-party manual instrumentation; the extension composes if present but is
  never required.
- **Not doing in-process tail sampling.** Head-based ratio + force-sample only;
  error/slow-biased retention is a Collector concern, documented not built.
- **Not sampling metrics.** Sampling applies to traces only; metrics are always
  complete aggregates.
- **Not instrumenting DB/outbound HTTP yet** (Phase 8 sketch only), and not
  touching the XSD/XSL config machinery — the settings family rides the existing
  permissive settings schema.
- **Not adding a `FactoryConfigHandler` entry** — the provider is a DI singleton,
  not a startup-sequenced factory.
