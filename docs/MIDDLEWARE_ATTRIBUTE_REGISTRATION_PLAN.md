# Plan: make `#[Middleware]` actually register middleware

**Status: implemented.** Sections below are the original design; where the
actual implementation diverged (mainly steps 3 and 5 — no filesystem
discovery, no compiled/cached artifact), a note says so inline. See
`docs/MIDDLEWARE.md`'s "Attribute-based registration" section for the
user-facing result.

## Problem

`Quiote\Middleware\Attribute\Middleware` (`Quiote/Middleware/Attribute/Middleware.php`)
is applied to every built-in middleware class with real `phase`/`priority`/`before`/
`after`/`enabled` metadata, but nothing ever reads it. `docs/MIDDLEWARE.md:108-113`
says so explicitly. The actual order lives as a hard-coded sequence of
`$construct(...)` calls in `MiddlewarePipeline::doBuild()`
(`Quiote/Middleware/MiddlewarePipeline.php:76-108`), and the two representations
can drift silently — e.g. `SlotMiddleware`'s attribute says
`before: 'RoutingMiddleware'`, which is true only because someone also placed the
matching `$construct()` call in the right spot by hand.

Goal: scan `#[Middleware]` attributes, compute an order from them, and make that
the source of truth — for both framework middleware and application middleware —
while keeping `MiddlewareCatalog::register()` and `replaceCoreStack()` working as
escape hatches.

## Prior art to copy

`Quiote/Routing/Compiler/AttributeRouteScanner.php` already solves this shape of
problem for `#[Route]`: glob-discover candidate files, `require_once` + reflect
each class, pull attribute instances via `getAttributes(Route::class,
ReflectionAttribute::IS_INSTANCEOF)->newInstance()`, build a plan object, hand it
to `RouteCollectionBuilder`, and cache/commit the compiled result
(`Quiote/Routing/Generated/*Routes`). The middleware scanner should be structured
the same way, living in a new `Quiote/Middleware/Compiler/` directory, and its
output cached through the same mechanism `ConfigCache` already uses for compiled
config (`Quiote/Config/ConfigCache.php:559-583`) rather than re-scanning every
request. `Container::$reflectionCache` (`Quiote/DI/Container.php:45,135-138`) is
the model for why this is safe to cache for the process lifetime.

## Steps

### 1. Resolve ambiguity in the attribute's `before`/`after` fields

Existing attribute usages pass short class names (`'RoutingMiddleware'`), not
FQCNs, e.g. `PayloadParsingMiddleware.php:21`. Decide now: accept either a short
name or an FQCN, and resolve short names against the scanned candidate set,
erroring on an ambiguous or unresolvable match. Document this in the attribute's
docblock since it's a behavior change from purely decorative.

### 2. Define canonical phase ordering

The attributes in use today reference these phases, in this implied order
(matching current `doBuild()` layout): `bootstrap`, `pre_routing`, `pre`,
`routing`, `before_action`, `action`, `after_action`, `finalize`. Encode this as
an explicit ordered list (e.g. a `MiddlewarePhase` enum or class constant array)
that the scanner/sorter treats as authoritative — phase is the primary sort key,
`before`/`after` constraints apply within/across adjacent phases, `priority` is
the tiebreaker within a phase (higher runs earlier, matching the existing
registered-middleware convention in `docs/MIDDLEWARE.md:96-101`).

### 3. Build `Quiote/Middleware/Compiler/MiddlewareAttributeScanner.php`

**Implemented differently than originally planned:** there is no directory
convention to glob for middleware (unlike actions, which always live under a
module's `Actions/` tree — confirmed by checking `samples/` and
`tests/sandbox/`/`tests/fixtures/`, none of which have a `Middleware/`
subdirectory). Rather than invent one speculatively, the scanner takes an
explicit `iterable<string>` of candidate FQCNs instead of doing its own
filesystem discovery:

- `MiddlewarePipeline::doBuild()` supplies the framework's own middleware
  FQCNs (the same classes it has factories for) plus whatever app code has
  registered via the new `MiddlewareCatalog::registerAttributed($fqcn)` (a
  lightweight sibling of `register()` — no factory, no before/after/priority
  args, since that all comes from the class's own attribute).
- For each candidate: skip if the class doesn't exist or doesn't implement
  `Psr\Http\Server\MiddlewareInterface` (diagnostic), skip silently if it has
  no `#[Middleware]` attribute (attribute presence is what makes a class a
  scan candidate at all — keeps `MiddlewareCatalog::register()` and
  non-attributed classes unaffected), otherwise build a `MiddlewareDefinition`
  (fqcn, phase, priority, before, after, enabled).
- Diagnostics collected: duplicate candidates, class-not-found,
  not-a-middleware. (Unresolvable/ambiguous `before`/`after` targets are
  diagnosed by the resolver in step 4, not the scanner, since resolving those
  references requires the full candidate set.)

### 4. Build the ordering pass

A small topological sort: group by phase (per step 2's canonical order), then
within/adjacent-to a phase apply `before`/`after` edges, then break remaining
ties by `priority` (desc) then discovery order (stable). Detect and report
cycles instead of silently picking an order — this is new failure surface that
route scanning doesn't have to deal with (routes don't have `before`/`after`).

### 5. Compile + cache the plan

**Not implemented as a separate compiled artifact.** Investigation found that
`#[Route]` scanning itself has no dedicated compiled-cache step either — a
`Routing::build()` call re-scans directly, relying on the pipeline/routing
object being built once and reused for the worker's lifetime (the same
`$built` flag `MiddlewarePipeline` already had). The middleware scanner
follows that same, already-established pattern: it runs once inside
`doBuild()`, guarded by the pre-existing `$built` cache, not per request. A
separate `Quiote/Middleware/Generated/*` artifact and `middleware:compile`/
`middleware:list` CLI command remain a reasonable future addition if scan cost
ever matters, but weren't justified for the current candidate-list size
(under 30 classes).

### 6. Wire the compiled plan into `MiddlewarePipeline::doBuild()`

Implemented as designed, with one clarification: every framework middleware
needs constructor args the container can't autowire generically (`Controller`,
`Routing`, `Context`, closures), so `doBuild()` keeps its FQCN → factory map for
those 17 classes exactly as before — only the *order* in which the map is
iterated now comes from the resolved plan instead of a literal sequence of
calls. Attribute-registered *app* middleware (no entry in that map) falls back
to `$context->getContainer()->get($fqcn)`, per the original plan.

`MiddlewareCatalog::isEnabled()` (`Quiote/Middleware/MiddlewareCatalog.php:35-44`,
driven by `<middleware_config>` XML) must still be able to disable an
attribute-scanned entry — treat config as the final override on top of the
attribute's own `enabled` flag, not a replacement for it.
`MiddlewareCatalog::register()`'s `insertRegistered()` splice
(`MiddlewareCatalog.php:60-69,110,144-165`) and `replaceCoreStack()`
(`84-137`) keep working unchanged — `replaceCoreStack()` bypasses the plan
entirely by design, and `register()`'d middleware is spliced into the
attribute-derived order the same way it's spliced into the hard-coded order
today.

**Tie-break rule:** if the same FQCN is both scanned via `#[Middleware]` and
passed to `MiddlewareCatalog::register()`, the `register()` call wins outright
— its `before`/`after`/`priority` completely replace the attribute-derived
placement for that class, and its factory closure is used instead of container
resolution. `register()` is the more explicit, request-time signal (it's also
how a class can be positioned without ever being scanned, e.g. it lives outside
scanned directories). The scanner should still surface a diagnostic/log entry
when this happens, since it usually means the attribute value is stale and
should be corrected instead of silently overridden.

### 7. Safety net: parity test before cutover

Added as `tests/tests/unit/middleware/MiddlewareAttributeOrderingTest.php::testAttributeScannedOrderMatchesLegacyHardCodedOrder`.
It caught real drift: several attributes described a stack position that
hadn't matched the real `doBuild()` order in a long time (proving the "never
validated" risk called out above) — `SessionMiddleware` claimed
`after: RoutingMiddleware` but is actually constructed second overall (before
routing even runs); `CsrfInjectionMiddleware`, `CsrfValidationMiddleware`,
`SlotMiddleware`, and `DispatchMiddleware` all named the wrong phase or a
stale before/after target. All were corrected to match the real order (see the
`#[Middleware(...)]` attributes on those classes), and `ErrorHandlingMiddleware`/
`AssetAggregationMiddleware` — which had no attribute at all — gained one.

### 8. Update docs

- Rewrite `docs/MIDDLEWARE.md:108-113` (currently states the attribute is
  decorative) to describe real scanning behavior, short-name resolution rules,
  and how `enabled`/config-toggle interact.
- Note that `Quiote/Runtime/PsrPipelineBuilder.php` is unrelated dead code (calls
  a `MiddlewarePipeline::add()` that no longer exists) — delete it in a separate
  small cleanup commit, not as part of this feature.

## Testing

- Unit tests for the scanner: attribute presence/absence, short-name vs FQCN
  `before`/`after` resolution, ambiguous-name error, cycle detection.
- Unit tests for the sorter: phase ordering, priority tiebreak, before/after
  across phase boundaries.
- The parity test from step 7.
- Extend `MiddlewareRegistrationTest.php`/`MiddlewarePipelineTest.php` to cover
  an attribute-scanned app middleware landing in the expected position alongside
  a `MiddlewareCatalog::register()`'d one.
- `ConfigHandlersConfigHandlerFormatDriverTest.php`-style coverage for the
  attribute `enabled: false` + `<middleware_config>` override interaction.

## Out of scope

- Changing the `MiddlewareCatalog::register()` public API.
- `replaceCoreStack()` behavior — it remains a full manual override, untouched
  by scanning.
- Per-request scanning — the compiled plan is built once (console/build step or
  first-boot-and-cache), matching how routes are compiled.
