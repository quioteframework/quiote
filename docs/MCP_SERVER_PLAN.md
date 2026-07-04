# MCP server for Quiote — plan

Status: **partially implemented**, in-tree under `Quiote\Mcp\*` (merged to `main`; validator→schema
work on branch `feat/mcp-validator-schema`). Phases 0–3 (including the validator→JSON-Schema
mapping) and Phase 4A are done and tested (see §13); Phase 4B (OAuth 2.1), resource/prompt
attribute discovery, stateless HTTP, and the extensions framework are not started — see `TODO.md`
for the live list.
Related seams: `Quiote/Routing/Compiler/AttributeRouteScanner.php`, `Quiote/Middleware/*`,
`Quiote/Console/Application.php`, `Quiote/Plugin/*`, `Quiote/Context.php` (`handle()` is what the
actions-as-tools bridge actually drives requests through, not `ActionExecutor` directly — see §7),
`Quiote/Validator/Compiler/ValidatorCompiler.php`, `Quiote/Http/ProblemDetails.php`.

## 1. Goal

Let a Quiote application expose itself as a **Model Context Protocol (MCP) server** — so AI
agents (Claude, IDE assistants, etc.) can call your app's capabilities as MCP **tools**, read
its data as **resources**, and use **prompts** — with minimal app code. The framework provides
the transport, discovery, DI wiring, auth, and telemetry; the app author annotates a method and
it becomes a tool.

The headline feature: **an existing `#[Route]` action can be exposed as an MCP tool by adding one
attribute**, reusing its validators (→ input schema), DI, and execution path.

> **This is the substrate, not the headline product.** The flagship consumer of this capability is
> the **Quiote Assistant MCP** — a server that teaches AI agents (Claude Code, Copilot, …) how to
> build with Quiote — which we build *as a Quiote app using this very capability* (dogfooding). That
> product has its own plan: `docs/MCP_ASSISTANT_PLAN.md`. Building it is also the best acceptance
> test of this capability: if the assistant works, the app-as-MCP-server feature works.

## 2. Decisions

1. **Build on the official `mcp/sdk`, don't reimplement the protocol.** The official PHP SDK
   (`mcp/sdk`, github `modelcontextprotocol/php-sdk`) is maintained by the **PHP Foundation +
   Symfony team**, framework-agnostic, with attribute-based discovery and a `Server::builder()`
   API. Quiote is already built on Symfony components, so it's the natural engine. We own the
   *binding* (transports, DI, attribute discovery via our scanner, auth, telemetry, config),
   exactly as we did for the ORM adapters wrapping Eloquent/Doctrine/Cycle. `mcp/sdk` is an
   optional dep (`suggest` + `require-dev`), not a hard `require`. Caveat: it's pre-1.0
   (v0.6.0, "experimental") — expect API churn; isolate it behind our own thin facade (§4).

2. **Target protocol `2025-11-25` (stable) now; architect for `2026-07-28` stateless HTTP.**
   The imminent `2026-07-28` revision goes **stateless** (drops the initialize handshake +
   protocol session → scales behind a round-robin LB, no sticky sessions) and adds an extensions
   framework. Our transport layer must support both a stateful session mode and the stateless
   mode; stateless is the better fit for FrankenPHP workers (no shared session store). Pin the
   advertised protocol version in config.

3. **Two transports, both reusing existing Quiote infrastructure:** Streamable HTTP as a
   registered PSR-15 middleware; stdio as a `bin/quiote` console command. See §5.

4. **Ship in-tree under `Quiote\Mcp\*`** with an opt-in `McpPlugin`, extractable later to
   `quioteframework/quiote-mcp`.

## 3. Architecture overview

```
                         ┌─────────────────── McpServer (our facade over mcp/sdk) ───────────────────┐
 transport in            │  McpCatalog: tools / resources / prompts (from 3 sources, §6)             │
 ┌───────────┐  JSON-RPC │  ├─ dispatch tool call → resolve handler via DI Container (per request)    │
 │ HTTP /mcp │──────────▶│  ├─ input validated against generated JSON Schema (from Validator IR, §7)  │  JSON-RPC
 │ (PSR-15)  │           │  ├─ OTel span per call + MCP logging → Quiote Log (§9)                     │──────────▶ client
 └───────────┘           │  └─ errors → JSON-RPC error / ProblemDetails                               │
 ┌───────────┐           │                                                                             │
 │  stdio    │──────────▶│  backed by mcp/sdk Server::builder() (protocol lifecycle, framing)          │
 │ (console) │           └─────────────────────────────────────────────────────────────────────────┘
 └───────────┘
```

`McpServer` is our own class; it configures an `mcp/sdk` server from the `McpCatalog` and runs it
on the chosen transport. Nothing outside `Quiote\Mcp` touches the SDK directly.

## 4. The facade (isolate the experimental SDK)

`Quiote\Mcp\McpServer` — builds and holds the configured SDK server.
- `configure(McpCatalog $catalog, McpConfig $config): void` — translate our catalog into
  `Server::builder()->addTool(...)`/`addResource(...)`/`addPrompt(...)` registrations, each tool's
  handler being a closure that resolves + invokes through Quiote's `Container` (§8).
- `handleHttp(ServerRequestInterface): ResponseInterface` — drive one Streamable-HTTP request.
- `runStdio(): int` — run the stdio loop.

Keeping every `mcp/sdk` symbol inside `McpServer` (+ a couple of adapter classes) means a
breaking SDK release touches one file, not the whole feature.

## 5. Transports

### 5.1 Streamable HTTP — a registered PSR-15 middleware
Add `Quiote\Mcp\Middleware\McpEndpointMiddleware`, registered via the plugin
(`PluginRegistrar::middleware(...)` → `MiddlewareCatalog`) and spliced into the pipeline **before
`SecurityMiddleware`** (the pipeline's default insertion point; see
`Quiote/Middleware/MiddlewarePipeline.php::insertRegistered()`). It:
- Matches the configured path (`mcp.path`, default `POST /mcp`; `GET /mcp` for SSE streaming);
  otherwise delegates to `$handler->handle($request)`.
- Reads the already-parsed JSON body from `getParsedBody()` (populated by
  `PayloadParsingMiddleware`, `application/json` strict) — no re-parsing.
- Delegates to `McpServer::handleHttp()` and returns its `ResponseInterface`.
- Uses `Quiote\Http\ProblemDetails` (RFC 9457) for transport-level errors, JSON-RPC error objects
  for protocol errors.

This sits *inside* the PSR-7 pipeline, so it inherits TraceMiddleware (OTel), payload parsing, and
(optionally) rate limiting — but bypasses MVC action/view dispatch entirely. It runs ahead of
`SecurityMiddleware` because MCP uses its own bearer/OAuth auth (§10), not session credentials.

SSE / streaming: for `2025-11-25` stateful mode, support the SSE response for server→client
notifications and progress. For `2026-07-28` stateless mode, a plain request/response POST is
enough (preferred under workers). Config selects the mode.

### 5.2 stdio — a console command
`Quiote\Mcp\Console\McpServeCommand` (`#[AsCommand('mcp:serve')]`, extends `AbstractAppCommand`):
`bootstrapApp()` then `McpServer::runStdio()`. Registered through
`PluginRegistrar::command(McpServeCommand::class)` → `PluginManager::contributedCommands()` →
`Console\Application::addContributedCommands()`. This is the simplest transport to ship first (no
HTTP/auth surface) and is what local clients (Claude Desktop, IDEs) launch as a subprocess.

## 6. Capability registration — one catalog, three sources

`Quiote\Mcp\McpCatalog` (static process-global registry, same pattern as `MiddlewareCatalog` /
`DatabaseDriverRegistry`) collects tools/resources/prompts from:

1. **Attribute discovery.** Reuse the SDK's own attributes (`#[McpTool]`, `#[McpResource]`,
   `#[McpPrompt]`) rather than inventing ours — less surface, SDK-native schema derivation. A
   scanner modeled on `AttributeRouteScanner` walks `{core.module_dir}/{Module}/Mcp/**` plus
   `PluginManager::moduleDirectories()`, so app *and* plugin capabilities are auto-discovered.
   Discovery is cached/generated like routes (a `cache:warmup` contribution), not scanned per
   request.
2. **Manual registration via the plugin seam.** New `PluginRegistrar` methods:
   `mcpTool(string $handlerFqcn, ?string $method = null, ...)`, `mcpResource(...)`,
   `mcpPrompt(...)` → `McpCatalog::add*()`. Lets a plugin contribute tools without the attribute
   scan.
3. **Existing `#[Route]` actions exposed as tools** — the killer feature (§7).

## 7. Exposing existing actions as tools (the killer feature) — IMPLEMENTED

Add `#[McpTool]` to an existing action class (which already carries `#[Route]`).
`Quiote\Mcp\Compiler\ActionToolScanner` (modeled on `AttributeRouteScanner`) finds every
`#[Route]` action additionally carrying `#[McpTool]` — opt-in via `mcp.expose_actions` (default
`false`), scanned lazily inside `McpServer::build()`, not yet cached by `cache:warmup` (see
`TODO.md`). `Quiote\Mcp\Bridge\ActionToolAdapter` (registered per action via the SDK's
`Builder::add(Tool, ToolHandlerInterface)` explicit-registration entry point, since each instance
is bound to its own route name/method) maps a `tools/call` to the action's execution path:
- **Deviation from the original plan:** rather than building a synthetic PSR-7 request and
  calling `ActionExecutor::execute(...)` directly, the adapter drives the synthetic request through
  **`Context::handle()`** — the same entry point a real HTTP request uses. `ActionExecutor::execute()`
  has preconditions (a canonical `WebRequest` and a validation decision) that only the middleware
  pipeline satisfies; going around it would mean either duplicating that pipeline logic or skipping
  validation entirely, which contradicts "reuses … validation". Driving the full pipeline instead
  means the action gets the exact same DI, verb dispatch (`executeRead`/`executeWrite`/…), and
  validation a normal HTTP call would get, for free — no new invariants to hand-satisfy.
- Path parameters vs. extra arguments are split using the route's own compiled path variables
  (`Route::compile()->getPathVariables()`); the concrete path is built via a
  `Symfony\Component\Routing\Generator\UrlGenerator` constructed from the context's own
  `RouteCollection`/`RequestContext`. Extra arguments ride as query params (GET/HEAD) or a JSON
  body (other verbs).
- A non-2xx response, or any exception `Context::handle()` throws, becomes an
  `Mcp\Exception\ToolCallException` (rendered as an `isError: true` tool result), not a JSON-RPC
  protocol error.
- **Input schema from validators — IMPLEMENTED.** `Quiote\Mcp\Compiler\ValidatorSchemaMapper`
  maps the action's validator IR (`ValidatorPlan`, from parsing its
  `{module}/Validate/{action}.xml` via the existing `ValidatorCompiler`) to the tool's
  `inputSchema`, scoped to the action verb the route's HTTP method dispatches to
  (`HttpMethodMapper`). String→`minLength`/`maxLength`, Number→`integer`/`number` + `minimum`/`maximum`,
  Email→`format: email`, Inarray→`enum`, Regex→`pattern` (positive, unflagged matches only),
  Boolean/Json/DateTime/IsNotEmpty mapped too; `required` reflects each validator's `required` flag.
  It is deliberately **descriptive, not a faithful re-encoding**: the schema always keeps
  `additionalProperties: true`, operator groups (and/or/not/xor) are flattened to a union of their
  fields rather than modeled as allOf/anyOf, and anything unmappable (a negative/flagged regex, an
  unrecognized validator class) degrades to a looser description rather than dropping the field —
  matching the §15 "permissive schema + server-side validation" stance, since real enforcement still
  happens on dispatch. Because the SDK validates a `tools/call`'s arguments against `inputSchema`
  before invoking the handler, a schema-violating call is now rejected as invalid params *before*
  dispatch, on top of the validators running again during dispatch. Fallback when no XML validator
  file exists (e.g. the action validates via a hand-written fluent builder, which produces no IR),
  parsing fails, or the rules yield nothing describable: the permissive
  `{"type":"object","properties":{},"additionalProperties":true}` schema (built via `Tool::fromArray()`
  so the SDK normalizes the empty `properties` array to a JSON object — passing `[]` directly makes
  opis/json-schema reject every call with "properties must be an object").
- **Output schema** comes through as-is from `#[McpTool(outputSchema: ...)]` when the action author
  supplies one; nothing is derived from the route's `outputType` automatically.
- Result: your web endpoints become agent tools with correct DI/dispatch/validation and a
  validator-derived input schema, for the cost of one attribute. Opt-in per action; nothing is
  exposed by default (`mcp.expose_actions = false`).

## 8. DI & worker-mode integration

- Tool/resource/prompt handler classes are resolved from the Quiote `Container` on each call
  (`Container::make()` for per-execution instances; request-scoped services honored).
- Under FrankenPHP workers, the pipeline's existing request-boundary reset clears request-scoped
  state between MCP calls; adapters holding per-request state implement `ResetInterface`.
- **Prefer stateless HTTP (`2026-07-28`)** in worker deployments — no MCP session store, each
  request self-contained, horizontal scaling by round-robin. Stateful mode (session id +
  optional SSE) remains available for `2025-11-25` clients via an in-process/PDO session store.

## 9. Telemetry & logging

- Wrap each tool/resource/prompt invocation in an OTel span (Quiote already ships
  open-telemetry/* + semantic conventions), attributes: `mcp.method`, `mcp.tool.name`,
  arg/result sizes, outcome. Spans nest under the existing request span from TraceMiddleware.
- Bridge MCP server-side `logging` notifications to `Quiote\Logging\Log`.
- The existing `telemetry:dashboard` TUI can grow an MCP panel (calls/sec, error rate, p95 per
  tool) — cheap once spans/metrics exist.

## 10. Security (phased — remote MCP needs real auth)

The HTTP endpoint runs before `SecurityMiddleware` and does its own auth (no session/CSRF):
- **Phase A — bearer token.** `Quiote\Mcp\Middleware\McpAuthMiddleware` validates a token
  (config static token or a pluggable `McpAuthenticator`), maps it to a `SecurityUser` /
  `RbacSecurityUser` with roles. Tools can then declare required credentials (reuse the action's
  `getCredentials()` / RBAC `hasRole()`), so RBAC gates which tools a caller may call/list.
- **Phase B — OAuth 2.1 resource server** per the MCP auth spec: protected-resource metadata
  endpoint, token validation/introspection, scopes → roles. This is the enterprise story.
- **Rate limiting** via the existing `LoginThrottle` / Symfony `RateLimiterFactory`
  (`PdoRateLimiterStorage`) keyed by token/IP. CSRF is bypassed for the token-auth endpoint.

## 11. Configuration

New `mcp.*` settings family (`SettingConfigHandler`):
```
mcp.enabled            = false
mcp.transports         = ['http', 'stdio']
mcp.path               = '/mcp'
mcp.protocol_version   = '2025-11-25'      # or '2026-07-28'
mcp.stateless          = true              # HTTP mode
mcp.server_name        = 'my-app'
mcp.server_version     = '1.0.0'
mcp.auth               = 'bearer'          # 'none' | 'bearer' | 'oauth2'
mcp.expose_actions     = false            # allow #[McpTool] on #[Route] actions
mcp.module_dirs        = []               # extra scan roots (defaults: module_dir + plugin dirs)
```
Enable the feature by adding `McpPlugin` to the `plugins` key.

## 12. Flagship consumer — the Quiote Assistant MCP (dogfooding)

The primary *product* built on this capability is a server that gives AI agents knowledge of Quiote
and tools to build with it (à la Laravel Boost / Symfony MCP), implemented as a Quiote app that
uses the `#[McpTool]`/`#[McpResource]`/`#[McpPrompt]` + stdio/HTTP transports defined here. Full
design: **`docs/MCP_ASSISTANT_PLAN.md`**. Sequencing note: the assistant needs stdio +
tools/resources/prompts (Phases 1, 5) but *not* the actions-as-tools bridge or OAuth — so it can
ship early and drive the capability's roadmap by real use.

## 13. Phasing

0. **Skeleton** — DONE. `mcp/sdk` dep (`require-dev` + `suggest`), `McpServer` facade,
   `McpCatalog`, `McpConfig`, `McpPlugin`, `mcp.*` settings (incl. `mcp.auth_token`, not in the
   original §11 list — added for Phase 4A).
1. **stdio + manual/attribute tools** — DONE (manual registration only). `mcp:serve`,
   `PluginRegistrar::mcpTool()/mcpResource()/mcpPrompt()` routing to `McpCatalog`, DI-resolved via
   `Bridge\ContainerAdapter`. `tools/list`/`tools/call` proven end-to-end (see `McpServerTest`).
   **Attribute discovery of plain (non-action) `#[McpTool]`/`#[McpResource]`/`#[McpPrompt]`
   classes is NOT implemented** — only the actions-as-tools scan (§7) reads `#[McpTool]`, and only
   on classes that also carry `#[Route]`.
2. **Streamable HTTP (stateful 2025-11-25)** — DONE. `Middleware\McpEndpointMiddleware`,
   ProblemDetails errors. **OTel spans per call are NOT implemented** (§9 is otherwise unstarted).
3. **Actions-as-tools bridge + validator→schema mapping** — DONE. Bridge dispatches via
   `Context::handle()` rather than `ActionExecutor::execute()` directly (see §7's "Deviation" note);
   `ValidatorSchemaMapper` derives the tool `inputSchema` from the action's validator IR (see §7),
   falling back to a permissive schema only when no XML validator file exists or the rules aren't
   describable.
4. **Auth** — Phase A (bearer) DONE: `Middleware\McpAuthMiddleware`,
   `Auth\McpAuthenticatorInterface` + default `Auth\StaticTokenAuthenticator`, `mcp.auth_token`.
   **Rate limiting and RBAC-gated tool listing are NOT implemented.** **Phase B (OAuth 2.1) is NOT
   started.**
5. **Resources + prompts**, **stateless HTTP (2026-07-28)**, extensions framework — NOT started.
   (Manual resource/prompt registration from phase 1 works; discovery/attribute-scanning and the
   stateless transport mode do not exist yet.)
6. **Dev-companion server** (§12) — NOT started; see `docs/MCP_ASSISTANT_PLAN.md`.

## 14. Testing

Status: unit + narrow integration coverage exists (`tests/tests/unit/mcp/*`, ~46 tests); the
`#[Group('integration')]`/real-MCP-client suite below was NOT built — everything so far drives
the SDK's own `Server`/`InMemoryTransport` in-process rather than a separate client process.

- **Unit** (done): `McpCatalog` registration; PSR-11 `Bridge\ContainerAdapter` autowiring
  behavior; `McpConfig` defaults/overrides; `ActionToolScanner` discovery;
  `ActionToolAdapter` request marshalling (path vs. extra params) and error mapping;
  `ValidatorSchemaMapper` validator-IR → JSON-Schema mapping (per validator class, method scoping,
  operator flattening, regex delimiter stripping, required handling — from hand-built IR nodes).
- **Integration** (partial): `McpServerTest`/`McpServerActionToolIntegrationTest` drive a full
  `initialize` → `tools/call`/`tools/list` round trip via `Mcp\Server\Transport\InMemoryTransport`
  (a custom subclass draining the SDK's outgoing-message queue, since the stock `InMemoryTransport`
  doesn't); `McpEndpointMiddlewareTest`/`McpAuthMiddlewareTest` do the same over real PSR-7
  request/response objects. The actions-as-tools tests additionally run end-to-end through the full
  `MiddlewarePipeline` with a real matched route (`ActionToolAdapter` → `Context::handle()`), and
  assert the derived schema is both advertised in `tools/list` and *enforced* — a schema-violating
  `tools/call` is rejected as invalid params before dispatch. **Not done**: driving `mcp/sdk`'s own
  *client* against stdio via `CommandTester`/a subprocess.
- **Auth** (done): unauthorized → 401 + `WWW-Authenticate`; `mcp.auth = 'none'` bypass. **Not
  done**: RBAC-filtered tool listing (no RBAC integration exists yet), rate-limit tests (no rate
  limiting exists yet).
- Adapter classes load without `mcp/sdk` installed and fail only at use (`McpServer::requireSdk()`
  mirrors the ORM adapters' `requireLibrary()` guard) — not covered by an automated test, but the
  guard exists and follows the established pattern.
- **Found and fixed along the way**: the sandbox test app's default routing
  (`Sandbox\App\Routing\SandboxRouting`, via its committed `Routes::build()` tree) had two
  malformed legacy routes (`test_ticket_444_sample2.archive` and `admin.reports`), both using
  invalid inline `{name:regex}` capture syntax whose own regex braces (`\d{2}`, `\d{4}`) broke
  Symfony's `UrlMatcher` compilation outright — crashing on *any* real dispatch once matched
  dynamically, and silently preventing `cache:warmup` from ever compiling a matcher for this app.
  No existing test before this work drove a live matched request through that routing class, so
  it was previously latent. Fixed in the committed generated route files (`BlogRoutes.php`,
  `AdminRoutes.php`) with the modern placeholder + `requirements` equivalent; mirrored in
  `routing.xml` for documentation consistency (that file isn't live-parsed at runtime).
  `cache:warmup` now compiles a routing matcher for the sandbox app successfully. A new, additive
  `mcp-action-tool-test` named context (`Quiote\Routing\AttributeRouting`, no legacy tree) was
  also added for the actions-as-tools bridge's own tests, independent of this fix.
- **Found and fixed along the way (core framework, not sandbox fixtures)**: getting a real
  request/response round trip working through the actions-as-tools bridge across two *different*
  named contexts in the same process (`web` for the HTTP middleware tests, `mcp-action-tool-test`
  for the action fixture) surfaced two more latent bugs in `Quiote\Context`/`Quiote\Middleware\*`,
  both invisible to any single-context app (the overwhelming common case) and undetected by the
  existing suite because nothing before this work called `Context::handle()` on more than one
  named context per process:
  - `Context::$psrKernel` (the cached `MiddlewarePipeline`) was `protected static` — one pipeline
    shared by *every* context profile, not one per profile. Whichever context handled a request
    first permanently decided which context's Controller/Routing served every later `handle()`
    call from any other context. Fixed by making it an instance property.
  - `ActionExecutor::buildRequestDataFromPsr()` was hardcoded to reuse
    `Context::getInstance('web')`'s canonical `WebRequest`, and `MiddlewarePipeline` never passed
    `ValidationMiddleware` its controller at all (despite the constructor accepting one) — so
    `ValidationMiddleware` *always* fell back to that same hardcoded `'web'` lookup too, for every
    request in every app. Once the `psrKernel` fix above let a second context's dispatch actually
    reach the target action, it silently got `web`'s stale `WebRequest` instead of its own —
    wrong parameter whitelist, wrong prior values, so path/query parameters from the real request
    never made it through. Fixed by threading the actual `Context` through
    `buildRequestDataFromPsr()` and wiring `$controller` into `ValidationMiddleware`'s factory
    (`FormPopulationMiddleware` had the same hardcoded fallback, fixed the same way).

  See `TODO.md` for the full detail. `phpstan level 5` on the touched files went from 130
  pre-existing errors to 128 (no new errors introduced by either fix).

## 15. Risks / open questions

- **SDK maturity** — `mcp/sdk` is pre-1.0; churn is likely. Mitigation: the `McpServer` facade
  (§4) — confirmed useful in practice, e.g. discovering that `Tool`'s constructor and
  `Tool::fromArray()` normalize an empty `properties` array differently (only `fromArray()`
  converts it to a JSON object, which the opis/json-schema validator requires) was a one-file fix.
  Re-evaluate `php-mcp/server` as a fallback engine if the official SDK stalls.
- **Protocol in flux** — `2026-07-28` finalizes ~end of July 2026; shipped `2025-11-25` only so
  far (`ProtocolVersion::from($config->protocolVersion)` — the config knob exists, but only that
  one version has actually been exercised).
- **SSE under FrankenPHP workers** — largely moot for now: the installed SDK version (`mcp/sdk`
  v0.6.0)'s `StreamableHttpTransport` doesn't implement GET/SSE at all (`OPTIONS`/`POST`/`DELETE`
  only), so this risk doesn't yet apply in practice.
- **Schema fidelity** — validator-IR → JSON-Schema mapping is now implemented (`ValidatorSchemaMapper`,
  §7), and the risk played out exactly as anticipated: not every rule maps cleanly, so the mapper is
  descriptive rather than exact (keeps `additionalProperties: true`, flattens operator groups, degrades
  unmappable rules to a looser shape) and real server-side validation on dispatch remains the source of
  truth. The permissive schema is now the fallback (no XML validator file / unparseable / nothing
  describable), not the default.
- **Auth spec surface** — bearer-token Phase A shipped; OAuth 2.1 Phase B not started.

## References

- MCP spec (2025-11-25): https://modelcontextprotocol.io/specification/2025-11-25
- 2026-07-28 release candidate (stateless HTTP): https://blog.modelcontextprotocol.io/posts/2026-07-28-release-candidate/
- Official PHP SDK (`mcp/sdk`): https://github.com/modelcontextprotocol/php-sdk — Packagist `mcp/sdk`
- 2026 roadmap: https://blog.modelcontextprotocol.io/posts/2026-mcp-roadmap/
