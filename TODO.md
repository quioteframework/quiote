# TODO

## MCP server (`docs/MCP_SERVER_PLAN.md`)

`docs/MCP_SERVER_PLAN.md` is **not fully completed** — Phases 0–3 and Phase 4A are implemented
(in-tree under `Quiote\Mcp\*`, branch `feat/mcp-server`), the rest is not. See the plan doc's §13
(Phasing), §14 (Testing), and §15 (Risks) for the detailed, up-to-date status of what's done vs.
outstanding. Remaining work, roughly in priority order:

- [ ] Validator-IR → JSON-Schema mapping for actions-as-tools input schemas (currently
      permissive: `{"type":"object","properties":{},"additionalProperties":true}`). Plan §7.
- [ ] `#[McpTool]`/`#[McpResource]`/`#[McpPrompt]` attribute discovery for plain (non-`#[Route]`)
      classes — only manual registration via `PluginRegistrar` and the actions-as-tools bridge
      exist today. Plan §6 item 1.
- [ ] Cache tool/resource/prompt discovery via `cache:warmup` instead of scanning live on every
      `McpServer::build()`. Plan §6 item 1.
- [ ] OTel spans per tool/resource/prompt call, and MCP `logging` notifications bridged to
      `Quiote\Logging\Log`. Plan §9.
- [ ] OAuth 2.1 resource-server auth (Phase B) + rate limiting for the HTTP endpoint. Plan §10.
- [ ] RBAC-gated tool listing (tools filtered by caller's roles/credentials). Plan §10.
- [ ] Resource/prompt attribute discovery + stateless HTTP (`2026-07-28`) + extensions framework.
      Plan §13 phase 5.
- [ ] Quiote Assistant MCP dev-companion server. Plan §12, full design in
      `docs/MCP_ASSISTANT_PLAN.md`.

## Done along the way (not MCP-scoped, kept here for visibility)

- [x] Two malformed legacy routes in the sandbox test app (`test_ticket_444_sample2.archive` and
      `admin.reports`, both using invalid inline `{name:regex}` capture syntax whose own regex
      braces broke Symfony's `UrlMatcher` compilation for *any* real dispatch) were discovered
      while testing the actions-as-tools bridge and fixed in
      `tests/sandbox/app/Routing/Generated/Modules/{BlogRoutes,AdminRoutes}.php` (the actual
      runtime fix) and documented/mirrored in `tests/sandbox/app/Config/routing.xml` (historical
      input only, not live-parsed). `cache:warmup` now successfully compiles a routing matcher for
      the sandbox app for the first time.
- [x] `Quiote\Context::$psrKernel` (the cached `MiddlewarePipeline`) was declared `protected
      static`, so it was shared across *every* named `Context` profile in the process instead of
      one per profile — whichever context called `handle()` first permanently decided which
      context's Controller/Routing served every subsequent `handle()` call from any other
      context. Latent because no app/test before this work called `handle()` on more than one
      named context within a single process. Fixed by making it a plain instance property
      (`Quiote/Context.php`).
- [x] `Quiote\Execution\ActionExecutor::buildRequestDataFromPsr()` was hardcoded to reuse
      `Context::getInstance('web')`'s canonical `WebRequest` regardless of which context was
      actually dispatching, and `MiddlewarePipeline` never passed `ValidationMiddleware` its
      controller (`new ValidationMiddleware()`, no arg) despite the constructor accepting one —
      so `ValidationMiddleware` *always* fell back to the same hardcoded `'web'` context lookup
      for its controller too, on every single request, in every app, regardless of which context
      served it. Harmless (accidentally correct) for the common case of a single "web" context;
      for any other/additional named context, dispatch would silently run through `web`'s stale
      request object — wrong parameter whitelist, wrong prior values. Fixed by threading the
      actual `Context` through `buildRequestDataFromPsr()` (new optional `?Context $context`
      param, passed from `DispatchMiddleware`'s two call sites via `$this->controller->getContext()`),
      passing `$controller` into `ValidationMiddleware`'s factory in `MiddlewarePipeline`, and
      fixing `FormPopulationMiddleware`'s equivalent hardcoded `'web'` fallback the same way.
      Confirmed via `phpstan level 5` that the touched files went from 130 pre-existing errors to
      128 (no new errors introduced).
