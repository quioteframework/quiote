# Plugin System & Extensibility Plan

## Status — implemented

All four features shipped and are green under `composer test` (1785 tests), plus
a real end-to-end boot against `samples/app` (plugin registered → `register()`
ran → config default applied → `KernelBootEvent` fired → the plugin's named HTTP
client resolved, built, and memoized through the actual context container).

- **Events** — `Quiote\Event\{Event, StoppableEvent, ListenerProvider,
  EventDispatcher, Events}` + `Quiote\Event\Lifecycle\{KernelBootEvent,
  RequestMatchedEvent, ActionBeforeEvent, ActionAfterEvent, ResponseSendingEvent}`.
  Emitted at: end of `Quiote::bootstrap()` (boot), `RoutingMiddleware` (matched),
  `ActionExecutor::execute()` (before/after), `Context::handle()` (response
  sending). Lifecycle events go through `Events::emit()` (gated on `hasListeners`,
  swallows listener exceptions) so a no-listener app pays one lookup and a bad
  listener can't break a request; `Events::dispatch()` stays PSR-14 fail-loud for
  direct callers.
- **HTTP client** — `Quiote\Http\Client\{HttpClient, HttpClientConfig,
  HttpClientFactory, CurlTransport, TransportFactory, Exception\*}` +
  `Quiote\Telemetry\Psr7HeaderSetter` (the outbound half of OTel Phase 7).
  Registered as the container singleton `HttpClientFactory` (alias
  `http_client_factory`).
- **Plugins** — `Quiote\Plugin\{PluginInterface, PluginRegistrar, PluginManager}`,
  booted in `Quiote::bootstrap()` after settings load; container services applied
  in `Context::registerCoreServicesInContainer()`; module dirs consulted by
  `AttributeRouteScanner`; commands by `Console\Application::addContributedCommands()`.
- **Correlation ID** — `Quiote\Support\CorrelationId` + `Context::handle()`
  adoption/echo, keys `core.correlation_id.header` (default `X-Correlation-Id`)
  and `core.correlation_id.expose` (default true).

**Divergences / boundaries worth noting** (each detailed inline below):
- `Psr7HeaderSetter` implements the OTel `PropagationSetterInterface` *directly*
  (not structurally) — `inject()` type-hints the interface, and the class is only
  ever referenced behind a `Trace::enabled()` gate, so the SDK is present when it
  loads (same pattern as the existing `Psr7HeaderGetter`).
- `Events::emit()` was added alongside `dispatch()` — the plan described the
  gate+try/catch behavior; it's a named method so emit sites read cleanly.
- Plugin **module directories** are wired into the route scanner's default set,
  but `AttributeRouteScanner` derives action FQCNs from `core.namespace_prefix`,
  so a plugin's `#[Route]` modules are discovered only when they follow that
  namespace convention; independent per-plugin namespaces + full multi-root
  module resolution in the `Controller` remain future work (as scoped).
- Plugin **commands**: `Console\Application::addContributedCommands()` is called
  in the constructor and re-callable; per the command-boundary note below,
  `bin/quiote` builds the app pre-bootstrap, so plugin commands appear once a
  bootstrap has populated the registry in-process.

---

Four related features, in dependency order. The first two are **enablers**; the
plugin system consumes them; the correlation-ID change is independent and small.

1. **Event/hook system** (`Quiote\Event`) — PSR-14 dispatcher + framework
   lifecycle events. The standard extension point plugins hook into.
2. **HTTP client abstraction** (`Quiote\Http\Client`) — PSR-18 based, named
   clients (dotnet `AddHttpClient()` style, memoized), curl + Guzzle transports,
   a central egress seam that unblocks outbound telemetry propagation + CLIENT
   spans.
3. **Plugin/extension system** (`Quiote\Plugin`) — one lifecycle that formalizes
   the framework's existing seams (config, DI, middleware, routes, output types,
   commands, modules, event listeners) into a single `PluginInterface`.
4. **Inbound correlation-ID header** — `Context::handle()` accepts a configurable
   inbound header (default `X-Correlation-Id`) instead of always generating, and
   echoes it back on the response.

Nothing here is on by default in a way that changes existing behavior: no
plugins registered = no change; the event dispatcher is a no-op with no
listeners; the HTTP client is opt-in; correlation-ID generation is unchanged
unless an inbound header is present.

## Dependencies

Add two **interfaces-only** PSR packages to `composer.json` `require` (both are
already present transitively via the optional OTel/tui dev packages; making them
first-class `require` is correct now that events + HTTP client are core
features, and neither triggers the `php-http/discovery` PSR-18-client
auto-install landmine — that keys off the virtual `psr/http-client-implementation`,
which we do **not** require):

- `psr/event-dispatcher` (PSR-14) — so our dispatcher implements the standard
  interfaces and is interoperable.
- `psr/http-client` (PSR-18) — the `ClientInterface::sendRequest()` contract our
  client + transports implement.

No Guzzle dependency: the default transport is a zero-dependency curl PSR-18
client; Guzzle (which already implements PSR-18) is used automatically **if
installed**, never required.

---

## 1. Event/hook system — `Quiote\Event`

No event infrastructure exists today (confirmed: no dispatcher/hook/plugin code
anywhere in `Quiote/`). Middleware covers the *request* lifecycle; this covers
*domain/framework* events that aren't request-pipeline-shaped.

- `Event` — plain base class (marker; carries no state of its own).
- `StoppableEvent extends Event implements Psr\EventDispatcher\StoppableEventInterface`
  — adds `stopPropagation()`/`isPropagationStopped()`.
- `ListenerProvider implements Psr\EventDispatcher\ListenerProviderInterface` —
  priority-ordered listener storage keyed by event class (and its parents/
  interfaces, so a listener on a base class or interface sees subclasses).
- `EventDispatcher implements Psr\EventDispatcher\EventDispatcherInterface` —
  `dispatch(object): object`; stops early for a stopped `StoppableEventInterface`;
  never throws out of a listener into the caller by default is **not** the choice
  here — a throwing listener propagates (fail-loud), matching PSR-14's stance that
  the dispatcher doesn't swallow. (Framework emit sites that must not be killed by
  a bad listener wrap their own `dispatch()` call.)
- `Events` — static facade mirroring `Quiote\Logging\Log` / `Quiote\Telemetry\Trace`:
  `Events::listen(string $eventClass, callable, int $priority = 0)`,
  `Events::dispatch(object): object`, `Events::hasListeners(string): bool`,
  `Events::dispatcher(): EventDispatcher`, `Events::reset()`. Process-global
  (worker-lifetime) listener registry, exactly like `MiddlewareCatalog` — plugins
  register listeners once at boot; they persist across worker requests.
- **Framework lifecycle events** (in `Quiote\Event\Events\`):
  - `KernelBootEvent(Context[] $contexts)` — emitted at the end of `Quiote::bootstrap()`.
  - `RequestMatchedEvent(ServerRequestInterface, module, action, ?routeName, outputType)`
    — emitted in `RoutingMiddleware::process()` after a successful match.
  - `ActionBeforeEvent(ActionDescriptor)` / `ActionAfterEvent(ActionDescriptor, ActionExecutionContext)`
    — emitted around `ActionExecutor::execute()`. `ActionBeforeEvent` is a
    `StoppableEvent` (a listener could short-circuit — future use).
  - `ResponseSendingEvent(ServerRequestInterface, ResponseInterface)` — emitted in
    `Context::handle()` just before returning the response (has request + response
    + context in scope).
- **Hot-path discipline**: every emit site is guarded
  (`if (Events::hasListeners(X::class)) Events::dispatch(new X(...))`) so a
  no-listener app pays one array lookup, not an object allocation, per event.
  Emit sites wrap `dispatch()` in try/catch and log — a buggy listener must never
  take down a request (same "never crash the request" posture telemetry holds).

## 2. HTTP client abstraction — `Quiote\Http\Client`

None exists; its absence is the stated blocker for outbound telemetry
propagation + CLIENT spans. Built on PSR-18.

- `Transport` = any `Psr\Http\Client\ClientInterface`. Two shipped:
  - `CurlTransport implements ClientInterface` — zero-dependency default, builds
    the PSR-7 response via the Nyholm PSR-17 factory (already a hard dep). Maps
    curl connity/timeout failures to PSR-18 `NetworkExceptionInterface` /
    `RequestExceptionInterface`.
  - Guzzle: `GuzzleHttp\Client` already *is* a PSR-18 `ClientInterface`, so no
    adapter class is needed — `TransportFactory` auto-selects it when installed.
    A thin `TransportFactory::default()` returns Guzzle-if-present else curl.
- `HttpClient implements ClientInterface` — the framework client. Wraps a
  transport and adds: a base URI, default headers, a small retry policy
  (configurable attempts + backoff on transient network errors / 429 / 5xx),
  convenience `request()/get()/post()/put()/delete()`, and — the payoff —
  **telemetry**: opens a `SpanKind::Client` span (`Quiote.Http.Client`,
  `"HTTP {method}"`), injects W3C `traceparent` into the outbound request via a
  new `Psr7HeaderSetter`, records `http.request.method`/`url.full`/
  `http.response.status_code`, records exceptions, ends the span. All telemetry is
  `Trace::enabled()`-gated and try/catch-guarded — telemetry never changes whether
  the request succeeds.
- `Psr7HeaderSetter implements OpenTelemetry\Context\Propagation\PropagationSetterInterface`
  (mirrors the existing getter-only `Psr7HeaderGetter`): `set(&$carrier, $key, $value)`
  → `$carrier = $carrier->withHeader($key, $value)`. This is the missing outbound
  half of Phase 7 in `docs/OPENTELEMETRY_PLAN.md`.
- `HttpClientFactory` — the dotnet-`AddHttpClient()` analogue:
  - `configure(string $name, callable(HttpClientConfig): void)` — register a named
    client's config (base URI, headers, transport, retries).
  - `client(string $name = 'default'): HttpClient` — returns a **memoized**
    instance per name (the whole point: reuse, don't reinstantiate). A worker
    keeps one instance per name for its lifetime.
  - `setDefaultTransportFactory(callable)` / `reset()`.
  Registered as a container singleton so app/plugin code can constructor-inject
  `HttpClientFactory` and pull named clients.

## 3. Plugin/extension system — `Quiote\Plugin`

The headline. Formalizes the existing seams into one lifecycle. **No new
low-level mechanism** — every contribution routes to a seam that already exists.

- `PluginInterface` — `name(): string` and `register(PluginRegistrar $r): void`.
- `PluginRegistrar` — the fluent contribution API passed to `register()`; each
  method routes to an existing seam:
  - `configDefault(string $key, mixed $value)` → `Config::set($key, $value, overwrite: false)`
    (set-if-absent: app `settings.*` always win; first plugin wins over later ones).
  - `service(string $id, callable|string|object $concrete, string $scope, string ...$aliases)`
    → deferred; applied per-`Container` via `PluginManager::configureContainer()`,
    and **only if not already bound** (app/core bindings win).
  - `middleware(string $fqcn, callable $factory, ?after, ?before, int $priority)` →
    `MiddlewareCatalog::register(...)`.
  - `attributedMiddleware(string $fqcn)` → `MiddlewareCatalog::registerAttributed(...)`.
  - `listen(string $eventClass, callable, int $priority)` → `Events::listen(...)`.
  - `moduleDirectory(string $dir)` → stored; `AttributeRouteScanner`'s default
    scan set becomes `[core.module_dir, ...plugin dirs]`, so a plugin's
    `#[Route]` module actions are discovered automatically.
  - `command(string $fqcn)` → stored; `Console\Application` registers contributed
    commands (see boundary note below).
  - `httpClient(string $name, callable)` → `HttpClientFactory::configure(...)`.
- `PluginManager` — the process-global registry + lifecycle:
  - `add(PluginInterface|class-string)` — programmatic registration (before bootstrap).
  - `bootFromConfig()` — called by `Quiote::bootstrap()` right **after settings
    load, before contexts are created** (the one seam that exists between those
    two steps). Reads the `plugins` config key (list of plugin class-strings),
    instantiates + adds them, then calls `register()` on every plugin in
    deterministic order (config order, then `add()` order), de-duped by class.
  - `configureContainer(Container, Context)` — applies deferred `service()`
    contributions; called from `Context::registerCoreServicesInContainer()` so it
    runs for every context however created, idempotently (register-if-absent).
  - `moduleDirectories()` / `contributedCommands()` — read by the scanner /
    console.
  - `reset()` — test isolation.
- **Ordering & override rules** (predictable, documented):
  - App settings load before plugins → app always wins on config.
  - Among plugins: declared order; **first writer wins** for config defaults and
    container services (set-if-absent semantics).
  - Middleware ordering is the existing deterministic attribute/`MiddlewareCatalog`
    resolution — unchanged.
- **Command contribution boundary — resolved for the common case.** `bin/quiote`
  builds the `Console\Application` *before* any bootstrap, so plugin-contributed
  commands only appear once a bootstrap has populated the registry in the same
  process (e.g. programmatic `new Application()` after `Quiote::bootstrap()`, or
  a command that bootstraps first). Fixed by having `bin/quiote` itself attempt
  a **best-effort, silent pre-bootstrap** before constructing `Application`: it
  resolves the app the same way `AbstractAppCommand` does (see
  `Quiote\Console\AppDirResolver` — `--app-dir`/`--env`, else
  `$QUIOTE_APP_DIR`/`$QUIOTE_ENV`, else a `.quiote.json` marker file
  (`{"app_dir": "...", "env": "..."}`, found by walking up from `$CWD`), else an
  upward search for `Config/settings.*`) and, if an app is found, bootstraps it
  — populating `PluginManager::contributedCommands()` before `Application`'s
  constructor reads it, so `mcp:serve` and any app-authored plugin command show
  up in `bin/quiote list`/`--help` with zero commands run first. Any failure
  (no app found, broken config) is swallowed silently — `quiote new` outside any
  app, and a plain `quiote --version`, are unaffected; a real command's own
  `bootstrapApp()` still surfaces genuine problems when it actually runs. This
  was deliberately **not** implemented as a parallel attribute-based command
  scanner: the existing `PluginRegistrar::command()` seam was already generic
  and correct, the only missing piece was *when* `bin/quiote` attempts a
  bootstrap, not *how* commands are declared. Deep multi-root *module
  resolution* in the `Controller` is likewise out of scope — plugin modules are
  discovered for **routing**; full module action/view resolution from multiple
  roots is future work.

## 4. Inbound correlation-ID header

`Context::handle()` currently always generates a fresh `correlationId` (there's a
literal `TODO` about supporting a configurable header). Change:

- Read `Config::get('core.correlation_id.header', 'X-Correlation-Id')`.
- If the inbound request carries that header with a non-empty, **sane** value
  (length-capped, control-bytes stripped — it becomes a log field and a response
  header, so it's untrusted input), adopt it as the correlation ID; otherwise
  generate as today.
- Echo the correlation ID back on the response under the same header name
  (gated by `core.correlation_id.expose`, default true), so a caller/gateway can
  tie its request to our logs. Done in `Context::handle()` after the pipeline
  returns.
- `quiote.rid` request attribute + `LogContext` enrichment are unchanged; they
  just carry the adopted value.

---

## Testing

- **Event**: dispatcher ordering by priority; stoppable propagation halt;
  listeners on a base class/interface see subclasses; `hasListeners()`; facade
  register/dispatch/reset; a listener throwing propagates (PSR-14 stance).
- **HTTP client**: `CurlTransport` against a real localhost socket server
  (same pattern the telemetry dashboard's `OtlpReceiverTest` uses); base
  URI/default-header merging; retry policy on a flaky endpoint; `HttpClientFactory`
  memoizes (same instance per name, distinct per name); `Psr7HeaderSetter` sets a
  header immutably; a CLIENT span + injected `traceparent` when telemetry is on
  (in-memory exporter).
- **Plugin**: a fake plugin contributing one of each kind; config default is
  set-if-absent (app wins, first-plugin wins); container service is
  register-if-absent; middleware/attributed/listener/module-dir/command/httpClient
  contributions land in their respective seams; `plugins` config key drives
  `bootFromConfig()`; ordering/dedup.
- **Correlation ID**: inbound header adopted; absent → generated; hostile value
  sanitized/capped; response echoes it; `expose=false` suppresses the response
  header; configurable header name.
- **Integration**: framework events actually fire at their seams
  (`RequestMatchedEvent` on a real routed request through the sandbox pipeline,
  `ResponseSendingEvent` on the way out), and a registered plugin's contributions
  are observable end-to-end.
