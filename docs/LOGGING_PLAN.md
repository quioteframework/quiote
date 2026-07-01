# Logging Subsystem Rewrite — PSR-3 + Serilog/.NET-style categories

**Status:** Framework cutover complete (2026-07-01) — app-side wiring (`../jakamo` `index.php` + call sites) and the DocBook manual remain. See §11.
**Goal:** Replace the bespoke `QuioteILogger` (5-level bitmask) + `DebugFlags` env
toggles with a PSR-3 logging stack that supports **per-category minimum levels**,
**structured message templates**, **ambient scopes**, and **pluggable sinks** —
with a **JSON-lines-to-stdout** sink as the default for FrankenPHP/Caddy/AKS.

**Big-bang migration — no legacy shim.** The bespoke logger, its appenders, the
`QuioteLoggerManager` wiring, and **all `QUIOTE_DEBUG_*` / `DebugFlags` code** are
removed outright in the same change. The application calls Quiote logging in few
places and is easy to repoint.

**Early config requirement:** log levels + sinks must be configurable in
`index.php` (the FrankenPHP worker script) **before** `QuioteKernel::run()` is
called — i.e. before the worker loop and before any request is handled. The
logging config is therefore a **self-contained static registry that does NOT
depend on `QuioteConfig`, the context, or the Quiote bootstrap** (so it is usable
during bootstrap itself, and set exactly once at worker startup).

---

## 1. Goals / non-goals

**Goals**
- PSR-3 `LoggerInterface` as the public surface (ecosystem interop: Monolog, etc.).
- Category loggers (`ILogger<T>` equivalent): each event stamped with a dotted category.
- Hierarchical, per-category **minimum level** config, longest-prefix wins (.NET semantics).
- Structured events: message template + property bag preserved to the sink.
- Ambient scopes / enrichers (Serilog `LogContext` / .NET `BeginScope`).
- Pluggable sinks, each with its own minimum level + optional per-category overrides.
- Default **compact JSON (JSONL) → stdout** sink, safe for Caddy/AKS/Log Analytics.
- O(1), allocation-free `isEnabled(Level)` hot-path guard (generalizes the
  `QuioteDebugLogger::isDebugEnabled()` we already added).
- Worker-mode safe: no cross-request state leakage of scopes.

**Non-goals (for this pass)**
- Log shipping/transport clients (Seq/Elastic HTTP sinks) — leave as future sinks.
- Replacing the `QuioteLoggerLayout` templating for text output beyond a basic formatter.
- OpenTelemetry span/log correlation (design leaves room; not implemented now).

---

## 2. Level model

The legacy bitmask (`FATAL=1 … DEBUG=16`, filtered via `$this->level & $msgLevel`,
5 levels, flag-match) is **deleted**. No back-compat mapping is kept.

New: an **ordinal enum** with `>=` (minimum-level) semantics, aligned to PSR-3 / RFC 5424
so PSR-3 `->log($level, …)` maps directly:

```php
namespace Quiote\Logging;

enum Level: int {
    case Trace     = 50;    // Serilog "Verbose"; maps to Debug on PSR-3 output
    case Debug     = 100;
    case Info      = 200;
    case Notice    = 250;
    case Warning   = 300;
    case Error     = 400;
    case Critical  = 500;
    case Alert     = 550;
    case Emergency = 600;

    public static function fromPsr(string $psrLevel): self { /* map psr/log LogLevel::* */ }
    public function toPsr(): string { /* inverse; Trace -> "debug" */ }
    public static function fromName(string $name): self { /* "info","App warns"… case-insensitive, for config */ }
}
```

A message passes a threshold when `$event->level->value >= $threshold->value`.

---

## 3. Public API

### 3a. Static `Log` facade — the early-config + acquisition surface

Because levels must be set in `index.php` before the kernel runs (and before DI
exists), the source of truth is a **static registry** with a thin facade. It has
no dependency on `QuioteConfig`/context/bootstrap.

```php
final class Log {
    // --- configuration (called in index.php, before QuioteKernel::run()) ---
    public static function setDefaultLevel(Level $level): void;
    public static function setLevel(string $categoryPrefix, Level $level): void;
    public static function setLevels(array $map): void;          // ['App.Orders'=>Level::Debug, ...]
    public static function addSink(SinkInterface $sink): void;
    public static function reset(): void;                        // tests / reconfigure

    // --- acquisition (used everywhere else) ---
    public static function for(object|string $classOrObject): CategoryLogger; // category = FQCN
    public static function create(string $category): CategoryLogger;
}

interface LoggerFactoryInterface {   // DI-friendly wrapper over the same registry
    public function create(string $category): \Psr\Log\LoggerInterface;
    public function for(object|string $classOrObject): \Psr\Log\LoggerInterface;
}
```

- `Log::create('Quiote.Routing')` / `Log::for($this)` → a
  `CategoryLogger implements Psr\Log\LoggerInterface`.
- `CategoryLogger` implements all 8 PSR-3 methods + `log()`, plus a fast
  `isEnabled(Level): bool` (not in PSR-3, exposed on our concrete type for guards).
- PSR-3 `$context` array carries structured properties; `{placeholder}` keys in the
  message are interpolated for text sinks but **the template + context survive to
  structured sinks** (see §5).
- `LoggerFactoryInterface` + a default `Psr\Log\LoggerInterface` are **ready** to
  register in the DI `Container` for constructor injection (both delegate to the same
  static registry, so DI and facade agree). **Deferred:** the `Container` is not yet
  wired into the runtime/bootstrap path (only its own unit test constructs it), so a
  registration today would have no consumer. Register it when the container is
  bootstrapped (the autowiring gap in the feature analysis). Until then, `Log::for()`
  is the acquisition API everywhere.

### 3b. Init ordering (`index.php`)

```php
require __DIR__.'/vendor/autoload.php';
use Quiote\Logging\{Log, Level};
use Quiote\Logging\Sink\JsonStdoutSink;

// Configure logging BEFORE the kernel — usable during bootstrap, set once per worker.
Log::setDefaultLevel(Level::Info);
Log::setLevels([
    'Quiote'         => Level::Warning,
    'Quiote.Routing' => Level::Debug,
    'App.Orders'    => Level::Debug,
]);
Log::addSink(new JsonStdoutSink(Level::Info));

// Now boot the framework + enter the FrankenPHP worker loop.
Quiote\Runtime\QuioteKernel::create(['app_dir' => __DIR__.'/app'])->run();
```

Config set here is process-global and immutable for the worker lifetime — worker-safe
(the only per-request logging state is `LogContext` scopes, §6, which are cleared on
reset). If a sink is registered by the time bootstrap logs anything, those bootstrap
lines are captured too.

---

## 4. Category minimum-level resolution

The static registry (§3a) holds a map of `category-prefix => Level`. Resolution for
a category = **longest matching prefix wins**, else `default`:

```
default            = Info
Quiote              = Warning     # quiet framework internals…
Quiote.Routing      = Debug       # …except routing, right now
App.Orders         = Debug       # verbose only for the code under investigation
```

- `CategoryLogger` resolves and **caches** its threshold on construction.
- The factory memoizes resolution per category string (worker-lifetime; config is
  static in worker mode).
- This is the direct fix for "turn on debug everywhere → a billion lines/request":
  scope `Debug` to `App.Orders` and nothing else changes.

**Hot-path guard** (generalizes what we already ship):
```php
if ($log->isEnabled(Level::Debug)) {
    $log->debug('parsed body {size} bytes', ['size' => strlen($raw)]);
}
```

---

## 5. Structured events

A value object carries everything a sink needs; nothing is flattened early:

```php
final readonly class LogEvent {
    public function __construct(
        public float          $timestamp,     // passed in (no Date.now in worker-reset-sensitive paths)
        public Level          $level,
        public string         $category,
        public string         $messageTemplate,   // "Order {orderId} shipped"
        public array          $properties,        // ['orderId'=>42, ...] (from PSR-3 $context)
        public array          $scope,             // merged ambient scope props (§6)
        public ?\Throwable    $exception = null,  // pulled from $context['exception'] per PSR-3
    ) {}
    public function renderMessage(): string { /* interpolate template ∪ properties */ }
}
```

- Text sink → `renderMessage()`.
- JSON sink → emits template + flattened properties + scope + level + category + ts.

---

## 6. Scopes / enrichers (ambient context)

```php
final class LogContext {                 // Serilog LogContext / .NET BeginScope
    public static function push(array $properties): ScopeToken;   // block scope: RAII, pops on token close/destruct
    public static function enrich(array $properties): void;       // request-lifetime: no token, removed only by clear()
    public static function current(): array;                       // merged stack
    public static function clear(): void;                          // WORKER RESET — see below
}
```

- Two acquisition styles (both implemented in Phase 1):
  - `push()` returns a `ScopeToken`; the frame pops when the token closes/destructs.
    **You must hold the token** — an unheld `push([...])` pops immediately (temporary
    destroyed at end of statement). Use for block-scoped context.
  - `enrich()` pushes a tokenless frame removed only by `clear()`. Use for
    request-lifetime enrichers (correlation id, user id) where there's no natural block.
- First enricher (Phase 3 wiring): `LogContext::enrich(['rid' => …])` from the
  correlation id already minted in `QuioteContext::handle()` (`quiote.rid`) at request
  start → **every line correlatable**; the matching `clear()` goes in `QuioteContext::reset()`.
- Optional enrichers: `pid`, `hostname`, authenticated `userId`.

**⚠ Worker-mode hazard (same class as the session leak we fixed):** the scope
stack is process-global. It **must be cleared per request** or one user's `userId`
bleeds into the next request's logs. Wire `LogContext::clear()` into
`QuioteContext::reset()` next to the `$_SESSION`/`session_id` clearing.

---

## 7. Sinks

```php
interface SinkInterface {
    public function emit(LogEvent $event): void;
    public function isEnabled(Level $level, string $category): bool; // per-sink level + overrides
    public function flush(): void;
}
```

Shipped sinks (all native — the legacy `QuioteLoggerAppender`s are deleted, not adapted):
- **`JsonStdoutSink`** (default for containers) — one compact JSON object per line to
  `php://stdout`. **Never `JSON_PRETTY_PRINT`** (newlines within a value are escaped by
  `json_encode`, so a stack trace stays one physical line = one Log Analytics record).
  Reserved keys + flattened properties; includes a `src:"app"` discriminator so KQL can
  separate app events from Caddy access logs. (CLEF `@t/@mt/@l` shape is an easy alt if
  Seq is added later.)
- **`TextStreamSink`** — human-readable, for local dev (stderr/file/any `php://` stream).
- **`FileSink`** — native replacement for the old file/rotating appenders (size- or
  date-rotation optional). Only if a file destination is still wanted; containers use
  stdout.

Each sink has its own minimum level (and optional per-category overrides), so e.g.
`Warning+` → file while `App.Orders` `Debug` streams to stdout.

---

## 8. Configuration

**Single source of truth = the programmatic `Log` facade (§3), called in `index.php`.**
No XML/`QuioteConfig` binding, no `QUIOTE_DEBUG_*`, no `DebugFlags`. This keeps logging
usable before bootstrap and settable exactly where the requirement demands.

```php
Log::setDefaultLevel(Level::Info);
Log::setLevels([
    'Quiote'         => Level::Warning,
    'Quiote.Routing' => Level::Debug,
    'App.Orders'    => Level::Debug,
]);
Log::addSink(new JsonStdoutSink(Level::Info));
Log::addSink(new TextStreamSink('php://stderr', Level::Debug)); // dev only
```

**Env is optional and app-driven** — the framework does not read env for logging, but
`Level::fromName()` makes it a one-liner in `index.php` if you want 12-factor overrides:

```php
Log::setDefaultLevel(Level::fromName(getenv('LOG_LEVEL') ?: 'info'));
foreach (['App.Orders','Quiote.Routing'] as $cat) {
    if ($v = getenv('LOG_LEVEL__'.str_replace('.', '_', $cat))) {
        Log::setLevel($cat, Level::fromName($v));
    }
}
```

So a per-category level change is a config line, never a redeploy of debug-everywhere —
the whole point of the rewrite.

---

## 9. Caddy / FrankenPHP / AKS output strategy

See the chat discussion; summary of the decisions baked into `JsonStdoutSink`:

- App logs and **Caddy's own logs are two separate streams**; both JSON at top level.
- App sink writes **bare compact JSON to `php://stdout`** — NOT via `error_log()`
  (which FrankenPHP may route through Caddy's logger and stringify into a `msg` field →
  "double JSON"). Caddy's JSON logger only formats Caddy's own entries, not arbitrary
  PHP stdout bytes, so straight-to-stdout avoids nesting.
- Compact encoding → each event is exactly one physical line → one Log Analytics record
  (fixes multiline splitting).
- `src:"app"` discriminator field for KQL separation from Caddy access logs.
- **Pre-cutover verification:** emit a known line (`{"src":"app","probe":123}`) and
  inspect `kubectl logs` / the raw Log Analytics record. Arrives verbatim → done.
  Arrives wrapped in a Caddy `msg` field → repoint the sink to a dedicated fd/file the
  platform tails.

---

## 10. File layout (`src/Logging/`)

```
Level.php                      enum (§2)
Log.php                        static facade: config + acquisition (§3a)
LogRegistry.php                the static store the facade/factory read (thresholds + sinks)
LoggerFactory.php              LoggerFactoryInterface impl for DI; delegates to LogRegistry (§3,§4)
CategoryLogger.php             Psr\Log\LoggerInterface + isEnabled() (§3)
LogEvent.php                   structured event VO (§5)
LogContext.php                 scopes/enrichers (§6)
Sink/SinkInterface.php
Sink/JsonStdoutSink.php        default container sink (§7,§9)
Sink/TextStreamSink.php
Sink/FileSink.php              optional native file/rotating sink (§7)
```

**Deleted in the same change:** `QuioteILogger`, `QuioteLogger`, `QuioteLoggerManager`,
`QuioteLoggerMessage`, `QuioteLoggerLayout` (+ layouts), all `Quiote*LoggerAppender`
classes, `QuioteDebugLogger`, `src/Util/DebugFlags.php`, and every `QUIOTE_DEBUG_*`
read. Their `<logger>`/`<logger_manager>` wiring is removed from `factories.xml`
(framework + test sandbox).

---

## 11. Migration — big bang (one PR)

No dual-run, no shim. Sequenced *within* the single change:

1. **Build the new stack.** ✅ DONE — `Level`, `Log`, `LogRegistry`, `LoggerFactory`,
   `CategoryLogger`, `LogEvent`, `LogContext`/`ScopeToken`, `SinkInterface` +
   `JsonStdoutSink`/`TextStreamSink`; `psr/log` added to composer; `LoggingTest` (17
   cases). DI registration deferred (§3a — no container consumer yet).
2. **Wire enrichers + worker reset.** ✅ DONE — `LogContext::enrich(['rid' => …])` in
   `QuioteContext::handle()` (reusing `quiote.rid`, with a defensive `clear()` first);
   `LogContext::clear()` in `QuioteContext::reset()`. Regression tests added to
   `QuioteContextExtendedCoverageTest` (reset clears scope; handle enriches + starts fresh).
3. **Convert all framework call sites (~46).** ✅ DONE — all `QuioteDebugLogger::*` /
   `->getLoggerManager()->getLogger()` replaced with `Log::for($this)` /
   `Log::create('Quiote.X')` category loggers; every `DebugFlags::$x` guard replaced with
   `if ($log->isEnabled(Level::Debug))`.
4. **Delete legacy.** ✅ DONE — `QuioteILogger`/`QuioteLogger`/`QuioteLoggerManager`/
   `QuioteLoggerMessage`/`QuioteLoggerLayout`/all appenders/`QuioteDebugLogger`/`DebugFlags`
   and every `QUIOTE_DEBUG_*` read removed; `<logger*>` stripped from `factories.xml`
   (framework + sandbox), the scaffolding template (`src/Build/templates/app/config/factories.xml.tmpl`),
   the samples (`samples/app/config/factories.xml`), and the stale `logging.xml` entry in
   `QuioteAPCuConfigCache`. `grep -rn 'QUIOTE_DEBUG_\|DebugFlags\|LoggerManager\|QuioteDebugLogger\|logging.xml' src/`
   (excluding `src/Logging/`) comes back clean. The `LoggerConfigHandler`/`logging.xml`
   config handler is gone.
5. **`index.php` config.** *(App-side — `../jakamo`, not this repo.)* Set default + category
   levels + `JsonStdoutSink` before `QuioteKernel::run()` (§3b). Verify JSON reaches Log
   Analytics un-nested (§9).
6. **App cutover.** *(App-side — `../jakamo`.)* Repoint the application's few call sites to
   `Log::for($this)`.

> **Cutover status (2026-07-01):** the framework side (steps 1–4) is complete and the
> grep gate is clean. Remaining work is app-side (steps 5–6, in `../jakamo`) plus the
> DocBook manual (`docs/docbook/manual.xml`), which still describes the legacy
> logger/filter architecture wholesale — see note below.

---

## 12. Performance & worker-mode notes

- `isEnabled()` = one enum-value comparison against a cached threshold; no allocation,
  no context lookup (unlike today's `QuioteDebugLogger::doLog` chain when unguarded).
- Category threshold + sink `isEnabled` resolution memoized for the worker lifetime.
- Do NOT build `LogEvent`/interpolate/`json_encode` unless a sink will accept it —
  factory short-circuits when no sink is enabled for `(level, category)`.
- `LogContext` cleared per request (§6). No `Date.now`-style calls in reset-sensitive
  paths — timestamp is captured at event creation and passed in.
- JSON sink writes are line-buffered; `flush()` on request end / worker reset.

---

## 13. Testing

- `Level`: `fromPsr`/`toPsr` round-trip; `fromName` parsing (case-insensitive; Trace→"debug").
- Category resolution: longest-prefix wins; default fallback; memoization.
- `isEnabled` gate correctness per category and per sink.
- `Log` facade: config set before any logger creation is honored; `reset()` isolates tests.
- Structured event: template preserved; properties + scope merged; exception captured.
- `JsonStdoutSink`: single physical line per event (embedded newline in a value stays
  escaped); compact; discriminator present.
- **Worker scope isolation**: push scope in "request A", `LogContext::clear()`, assert
  "request B" sees no leaked properties.

---

**Resolved:** no legacy shim / big-bang (§11); `Trace` level included, maps to `"debug"`
on PSR output (§2); config is programmatic via `Log` in `index.php`, not `QuioteConfig`/env (§8).

**Still open:**
- JSON schema: flat reserved-keys shape (easiest KQL) vs CLEF (`@t/@mt/@l`, Seq-native).
  Recommend flat now, CLEF-compatible sink later.
- Category naming for framework: dotted `Quiote.Routing` vs FQCN `Quiote\Middleware\RoutingMiddleware`.
  Recommend FQCN via `for($this)` for app code (matches `ILogger<T>`), curated dotted
  names for framework subsystems (stable config keys).
- Sampling / rate-limiting of high-volume categories — future.
