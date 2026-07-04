# Whoops Error Handling Plan

**Update:** `WhoopsRenderer` now lives at `Quiote\Exception\Rendering\Whoops\WhoopsRenderer`,
physically split into its own composer package at `packages/whoops/` (developed in-tree,
symlinked via a path repository; see docs/MONOREPO_SPLIT_PLAN.md and
docs/PLUGIN_EXTRACTION_PLAN.md §2.4/§3) — not yet pushed to a standalone repo. `filp/whoops`
moved from the kernel's own `require` into the package's `require`. `ExceptionRenderer` and
`SafeRenderer` (below) are unaffected, still core, still in `Quiote\Exception\Rendering`.

## Background — what exists today

Exception rendering currently happens in **two competing places**:

1. **`Quiote\Middleware\ErrorHandlingMiddleware`** — the modern, correct path.
   Outermost middleware in the pipeline: catches any downstream `Throwable`,
   writes a dense diagnostic log line (class, message, throw site, request,
   exception chain, trace), content-negotiates (HTML template vs. JSON vs.
   plaintext via `Accept`/`output_type`), maps a few exception types to status
   codes, threads a correlation id, and returns a PSR-7 `Response`. It already
   has dev/prod awareness (`core.environment` regex + the `QUIOTE_DEBUG` env
   var) and resolves templates from `exception.default_template` /
   `exception.templates.{context}`.

2. **`Quiote\Exception\QuioteException`** (legacy) — an Agavi-era
   `printStackTrace`-style method that `include()`s an exception template and
   calls `exit($exitCode)`. It bypasses PSR-7 entirely and terminates the
   process mid-request — actively wrong under FrankenPHP worker mode. This
   looks vestigial now that `ErrorHandlingMiddleware` exists, but that must be
   **confirmed dead before removal** (same discipline as the filter/SOAP
   removals — trace the runtime, don't assume).

The client-facing templates live in `Quiote/Exception/templates/`:
`plaintext.php`, `simple.php`, and `shiny.php` — the last being a hand-rolled
attempt at a whoops-style pretty page. Replacing `shiny.php` with real
`filp/whoops` is a straight upgrade of something that already tries to be
whoops and does it worse.

Relevant existing dependencies: `middlewares/negotiation` and
`middlewares/payload` are already in `composer.json` (so the `middlewares/*`
PSR-15 family is already vendored), and there's a `ContentNegotiationMiddleware`.
`filp/whoops` and `middlewares/whoops` are **not** present. There is a
`core.debug` setting but no dedicated developer-exceptions switch.

## Goals

1. Delete the three bespoke exception templates and the legacy
   `QuioteException` render/`exit()` path; consolidate to a single catch point.
2. Use `filp/whoops` to render beautiful developer exception pages
   (content-negotiated: pretty HTML for browsers, JSON for API clients,
   plaintext for CLI).
3. A config switch — `core.developer_exceptions` — that turns the whoops
   developer view on, and off which falls back to a **safe generic** response
   with full detail sent only to the logs.

## The one design decision that drives everything: dev vs. prod are different renderers

Not "whoops, styled two ways." Two distinct strategies behind one catch point:

```
                 ErrorHandlingMiddleware  (the ONE catch point — unchanged role)
                          │
                          ▼
                  ExceptionRenderer  (chosen solely by core.developer_exceptions)
                   ┌──────┴───────────────────────────┐
      developer on ▼                                   ▼ developer off (default)
  WhoopsRenderer                                 SafeRenderer
  - HTML: PrettyPageHandler (frames, code,       - full detail → PSR-3 log ONLY
    superglobals, request)                       - client gets a generic response:
  - JSON: JsonResponseHandler                      · HTML: static 500 page, no internals
  - CLI:  PlainTextHandler                         · JSON: {error, status, correlation_id}
  full internals to the client                     · CLI:  plain "Internal error" + cid
                                                   NEVER a stack trace / superglobal to client
```

Content negotiation reuses the existing `middlewares/negotiation` +
`ContentNegotiationMiddleware` machinery so the HTML/JSON/CLI split is
consistent with the rest of the pipeline (this matters — the app serves both
browser UIs and Authorization-header API/React clients, which must get JSON
errors, not an HTML whoops page).

`SafeRenderer` may *use* whoops's `PlainTextHandler` purely to format the log
string (nice reuse), but its client output never contains internals.

## The config switch

`core.developer_exceptions` (bool) — the **sole** signal. There is no
"production mode": an environment is just a user-chosen name selecting a bunch
of settings (`development.*`, `testing.*`, etc. in `settings.xml`), and the
framework has no reliable way to know which one you *treat* as production. So
the renderer choice is driven purely by this explicit setting, never by
sniffing the environment name.

- **A standalone setting, default `false`. Explicitly NOT derived from
  `core.debug`.** `core.debug` carries a lot of unrelated, heavy behavior —
  historically (in Agavi) it forced *every configuration file to be reparsed on
  every request*, which is a big reason the framework got an undeserved
  "slow" reputation: people were unknowingly running in debug mode. Tying
  developer exception pages to `debug` would mean you can't get pretty errors
  without also paying that per-request cost, and can't turn on debug's
  diagnostics without leaking full exception pages. They are orthogonal
  concerns and get orthogonal switches.
- **Default effective value: OFF.** Safe by default; you opt into internals by
  explicitly setting `core.developer_exceptions` true in whichever environment
  block you want it (typically `development.*`).

The safety here is *not* a magic guard — it's off by default, opt-in per
environment, the user's responsibility to not enable it in the environment they
serve to the public. This work also **removes the existing fragile
environment-name detection** in `ErrorHandlingMiddleware`
(`preg_match('/^(prod|production)/i', $env)` and the `QUIOTE_DEBUG` env-var
check), replacing both with the single explicit setting.

## Resolved decisions (locked)

- **`filp/whoops` used directly inside `ErrorHandlingMiddleware`.** No
  `middlewares/whoops` — we already have the one catch point, so whoops is a
  *renderer* the existing middleware delegates to, not a second catch layer.
- **The legacy `QuioteException` render/`exit()` path is removed**, not kept
  behind a flag. (Removal is still done responsibly — verify call sites and
  reroute any live caller through the middleware before deleting — but the
  outcome is: it goes.)
- **`core.developer_exceptions` is its own setting, default `false`, fully
  independent of `core.debug`** — because `core.debug` triggers heavy unrelated
  behavior (e.g. per-request config reparsing) that must not be a prerequisite
  for pretty exception pages, and vice versa.

## Phases

### Phase 0 — retire the legacy `QuioteException` render path
Its `include()`+`exit()` method is going regardless. Before deleting: grep its
call sites (CLI/console, bootstrap, any non-middleware entrypoint), and route
anything that still reaches it through `ErrorHandlingMiddleware` instead, so the
single catch point becomes the only exception-rendering path. Then delete the
method. This is the filter/SOAP-removal discipline (check what's wired before
cutting), applied to a removal that's already decided.

### Phase 1 — dependencies + renderer seam
- Add `filp/whoops` (`^2.18`+). No `middlewares/whoops`.
- Introduce an `ExceptionRenderer` interface and refactor
  `ErrorHandlingMiddleware`'s inline rendering into a `SafeRenderer` (behavior
  identical to today's non-debug output). Pure refactor, tests stay green — the
  established "extract behind an interface first" move.

### Phase 2 — WhoopsRenderer (used directly by ErrorHandlingMiddleware)
- Implement `WhoopsRenderer` wrapping a `filp/whoops` `Run`, with
  `PrettyPageHandler` / `JsonResponseHandler` / `PlainTextHandler` selected by
  negotiated content type. `ErrorHandlingMiddleware` instantiates/holds it
  directly — no separate whoops middleware in the pipeline.
- **Feed whoops from the PSR-7 request, not superglobals** — in worker mode
  `$_SERVER`/`$_GET` are stale/empty; `PrettyPageHandler::addDataTable()` gets
  request method/uri/headers/attributes from the `ServerRequestInterface`.
- **Never call `Run::register()`** (the global PHP handler) — use whoops purely
  as an on-demand formatter invoked by the middleware, so it can't hijack the
  process handler across requests in a long-lived worker.
- Ensure whoops captures to a string / PSR-7 body (no direct `echo`, no `exit`).

### Phase 3 — wire the switch + nuke the old
- `ErrorHandlingMiddleware` picks `WhoopsRenderer` vs `SafeRenderer` from
  `core.developer_exceptions` alone, and its existing environment-name /
  `QUIOTE_DEBUG` sniffing is deleted in the same change.
- Delete `Quiote/Exception/templates/{plaintext,simple,shiny}.php`, the
  `exception.default_template` / `exception.templates.*` settings + their
  handling in `SettingConfigHandler`, and the confirmed-dead `QuioteException`
  render path. Update the `settings.xml` fixtures/samples that reference them.

### Phase 4 — CLI / console context
The forthcoming `quiote` console app (separate plan) should get whoops's
`PlainTextHandler` for uncaught command exceptions in dev — a natural, small
add once the renderer seam exists.

## What this is NOT / caveats

- **Whoops renders only when `core.developer_exceptions` is explicitly on.**
  With it off (the default), every client in *every* environment gets the safe
  generic response, with full detail going to the logs only. Safe-by-default is
  a security property, not a style choice.
- **Not adding a second catch layer.** One catch point
  (`ErrorHandlingMiddleware`); whoops is a *renderer* it delegates to, not a
  competing middleware (see decision points re `middlewares/whoops`).
- **Worker-mode correctness is a first-class requirement**, not an
  afterthought: no `Run::register()`, no `exit()`, no superglobal reliance, no
  global output-buffer takeover.

## Decision points

None open. All settled — see "Resolved decisions" and "The config switch":
filp/whoops directly (no `middlewares/whoops`), legacy `QuioteException` path
removed, no environment-name magic, and `core.developer_exceptions` as a
standalone `false`-default setting independent of `core.debug`.

## Testing

- `SafeRenderer`: assert no stack trace / superglobal / source ever appears in
  the client body across HTML/JSON/CLI; correlation id present; correct status.
- `WhoopsRenderer`: assert pretty HTML for `Accept: text/html`, JSON envelope
  for `application/json`, plaintext for CLI; assert request data comes from the
  PSR-7 request; assert the response is a real PSR-7 `Response` (no echo/exit).
- Switch: `core.developer_exceptions` on → `WhoopsRenderer`; off or unset →
  `SafeRenderer`. Assert the choice is driven only by this setting: unaffected
  by `core.debug` (debug on + developer_exceptions off → still the safe
  renderer) and unaffected by the environment *name* (an environment literally
  named "production" with the switch on still gets whoops — no name magic).
- Full suite + APCu + random-order regression, same as prior work.
