# CSRF Protection — Implementation Plan & Status

Addresses finding #5 of `SECURITY_AUDIT.md` (no CSRF protection anywhere in the framework).

## Status: implemented, physically split into `packages/csrf/`

Physically split into its own composer package at `packages/csrf/` (developed in-tree,
symlinked via a path repository; see docs/MONOREPO_SPLIT_PLAN.md and
docs/PLUGIN_EXTRACTION_PLAN.md §2.3/§3) — not yet pushed to a standalone repo. Both
middleware moved namespace from `Quiote\Middleware\*` to `Quiote\Security\Csrf\Middleware\*`
as part of the move; `symfony/security-csrf` moved from the kernel's own `require` into the
package's `require`. `Quiote::bootstrap()` still runs `CsrfPlugin` unconditionally today (a
"core default", see that file) so CSRF stays on by default while it's split-but-not-yet-opt-in.

Implemented on the security-fixes branch:

- **`symfony/security-csrf` ^8.0** added to `composer.json`.
- **`Quiote\Security\Csrf\QuioteSessionTokenStorage`** — Symfony `TokenStorageInterface` backed by the context's `QuioteStorage` (session), namespaced under `org.quiote.csrf.`.
- **`Quiote\Security\Csrf\CsrfManager`** — wraps Symfony's `CsrfTokenManager` (random `UriSafeTokenGenerator`, BREACH-masked values, constant-time `hash_equals` comparison) and exposes the config (`enabled`, `token_id`, `field_name`, `header_name`, `safe_methods`) plus `getTokenValue()` / `isValid()` / `removeToken()`.
- **`Quiote\Security\Csrf\Middleware\CsrfValidationMiddleware`** — rejects unsafe-method requests (non GET/HEAD/OPTIONS/TRACE) with **403** unless a valid token is present in the configured form field (parsed body) or header; per-route opt-out via `_csrf => false`. Registered via `CsrfPlugin` (see docs/PLUGIN_EXTRACTION_PLAN.md §2.3), spliced into the pipeline before `DispatchMiddleware`.
- **`Quiote\Security\Csrf\Middleware\CsrfInjectionMiddleware`** — always-on response pass that delivers the token two ways: (1) into server-rendered HTML — a hidden `<input type="hidden" name="_csrf_token" value="…">` in every non-GET `<form>` (skips `data-csrf="off"`) plus a `<meta name="csrf-token">` in `<head>`; and (2) a readable (non-HttpOnly) **`XSRF-TOKEN` cookie** (`Secure` on HTTPS, `SameSite=Lax`) on any session-bearing request regardless of content type — this is how a decoupled same-origin SPA (served from a different service/pod, so it never sees the rendered HTML/meta tag) obtains the token: it reads the cookie and echoes it in the `X-CSRF-Token` header. Registered via `CsrfPlugin` (wraps the response, so even a 403 carries a fresh cookie for retry). Implemented as a dedicated middleware rather than inside the Form Population filter, because FPF only runs when there is data to repopulate (it early-returns on a fresh GET render) and would therefore miss freshly-rendered forms.
- **Config directives** (read with secure defaults; no central registration needed): `core.csrf.enabled` (default `true`; set `false` in the test bootstrap), `core.csrf.token_id` (`quiote_csrf`), `core.csrf.field_name` (`_csrf_token`), `core.csrf.header_name` (`X-CSRF-Token`), `core.csrf.cookie_name` (`XSRF-TOKEN`), `core.csrf.safe_methods`.
- **Tests** — `packages/csrf/tests/CsrfTest.php` (12 tests): token roundtrip/rejection, validation pass/fail/opt-out/disabled, injection into POST forms / skip GET / `data-csrf=off` / non-HTML / meta tag.

> ⚠️ **Deployment note:** `core.csrf.enabled` defaults to **true**, so deploying this immediately enforces CSRF on all unsafe requests. Server-rendered HTML forms get tokens automatically via the injection middleware; **JS/XHR clients must send the token in the `X-CSRF-Token` header** (read it from the `<meta name="csrf-token">` tag), and **stateless API/webhook routes must opt out** with an `_csrf => false` route default. CSRF is disabled in the test environment (`test/bootstrap.php`) as is conventional.

### Not yet done / possible follow-ups
- Template helpers (`$this->csrfToken()` / `$this->csrfField()`) for manually-built or non-HTML templates.
- Double-submit-cookie strategy (`SameOriginCsrfTokenManager`) for stateless deployments.
- Token rotation per request (`core.csrf.rotate`).
- `Origin`/`Referer` cross-check against `core.trusted_hosts`.
- Integrating injection with the FPF DOM pass when FPF is active, to avoid a second HTML parse.

---

## Original plan (for reference)

## Goal

Ship first-class, on-by-default CSRF protection that:

- injects a token into every HTML form automatically (no per-form developer work),
- verifies the token on every unsafe request (`POST`, `PUT`, `PATCH`, `DELETE`) before the action runs,
- is opt-out per route/action (e.g. stateless API endpoints, webhooks), and
- integrates with the existing PSR-15 middleware pipeline and the Form Population layer.

## Architecture overview

Two cooperating pieces, mirroring the existing `FormPopulationMiddleware` / `ValidationMiddleware` split:

```
request → ... → CsrfValidationMiddleware (verify)  → Routing → Validation → Dispatch
response ←  CsrfInjectionMiddleware/FPF (inject token into <form>s)  ← ...
```

### 1. Token store & generator (`Quiote\Security\CsrfTokenManager`)

- Token = `random_bytes(32)` → `bin2hex`/base64url. Generated once per session, stored in the session via the existing `QuioteStorage` (`$storage->store('org.quiote.csrf.token', $token)`).
- Strategy: **synchronizer token** (per-session) by default; optionally **double-submit cookie** for stateless deployments (token in a `Secure`/`SameSite=Strict` cookie compared to a form field — no session needed).
- Constant-time comparison via `hash_equals()`.
- Optional per-form token rotation / TTL for high-security flows (config `core.csrf.rotate`).

### 2. Injection — extend the Form Population layer (`FormPopulationEngine` / `QuioteFormPopulationFilter`)

The FPF already parses the rendered response body into a DOM to repopulate field values — the ideal hook to also inject CSRF fields. Plan:

- After repopulation, for every `<form>` whose `method` (case-insensitive) is not `GET`, inject a hidden input:
  `<input type="hidden" name="<core.csrf.field_name>" value="<token>">`
  unless the form already contains that field, or carries an opt-out marker attribute (e.g. `data-csrf="off"`).
- Reuse the FPF's existing safe DOM defaults (`dom_substitute_entities=false`, `dom_resolve_externals=false`); no new parsing surface.
- Where FPF is disabled, provide a tiny `CsrfInjectionMiddleware` fallback that does the same DOM pass, plus a template helper `$this->csrfField()` / `$this->csrfToken()` for non-HTML or manually-built forms.
- Also expose the token as a `<meta name="csrf-token">` tag option for JS/XHR clients (so `fetch`/AJAX can send it via the `X-CSRF-Token` header).

### 3. Verification — `CsrfValidationMiddleware` (PSR-15, pre-routing/pre-dispatch)

- Runs early in the pipeline (before `DispatchMiddleware`, ideally before/with `ValidationMiddleware`).
- Skips safe methods (`GET`, `HEAD`, `OPTIONS`, `TRACE`).
- For unsafe methods: read the token from the request — form field `core.csrf.field_name`, or `X-CSRF-Token` header (for XHR) — and `hash_equals()` it against the session/cookie token.
- On mismatch/absence: short-circuit with **HTTP 403** (no action execution), a clear body, and `X-Quiote-Csrf: failed`.
- Opt-out: honor a route default (`_csrf => false`) or an action marker (e.g. `isCsrfProtected(): bool` returning false, mirroring `isSecure()`), so APIs/webhooks using token auth or signature verification can bypass.
- Defense-in-depth: optionally also verify `Origin`/`Referer` against the trusted-host list (the new `core.trusted_hosts`) when present.

### 4. Configuration (`QuioteConfig`)

| Directive | Default | Meaning |
|-----------|---------|---------|
| `core.csrf.enabled` | `true` | Master switch |
| `core.csrf.field_name` | `_csrf_token` | Hidden field / POST key |
| `core.csrf.header_name` | `X-CSRF-Token` | Header accepted for XHR |
| `core.csrf.strategy` | `synchronizer` | `synchronizer` or `double_submit` |
| `core.csrf.rotate` | `false` | Rotate token per request |
| `core.csrf.safe_methods` | `[GET,HEAD,OPTIONS,TRACE]` | Methods not checked |

The session cookie is already `SameSite=Lax` after finding #6, which provides a second, browser-enforced layer.

## Rollout

1. Implement `CsrfTokenManager` + config + unit tests (token generate/verify, constant-time, double-submit).
2. Add `CsrfValidationMiddleware` with the `#[QuioteMiddleware]` attribute; register pre-dispatch. Default-enabled.
3. Hook injection into `FormPopulationEngine`; add `CsrfInjectionMiddleware` fallback + template helpers.
4. Tests: form injection (DOM), 403 on missing/forged token, header path, opt-out path, double-submit mode.
5. Document opt-out for APIs and the JS/`<meta>` usage.

## Library options (instead of, or under, a hand-rolled manager)

Recommendation: **`symfony/security-csrf`** — small, well-audited, already in the same vendor family as the framework's other deps (`symfony/mime`, `symfony/routing`, `symfony/cache`). Provides `CsrfTokenManager`, `CsrfToken`, pluggable token storage (`TokenStorageInterface` — wrap `QuioteStorage`), and `SameOriginCsrfTokenManager` for stateless/double-submit. We'd implement the storage adapter + the two middlewares and the FPF injection; the token primitives come from the library.

Alternatives considered:
- **`paragonie/anti-csrf`** — solid, security-focused (Paragon Initiative), per-form tokens with locking/expiry; heavier, its own session assumptions, smaller ecosystem fit.
- **`slim/csrf`** — clean PSR-15 middleware (double-submit), but tied to Slim conventions and would still need the form-injection piece.
- **Hand-rolled** (as sketched above) — minimal deps, full control; acceptable given the primitives are just `random_bytes` + `hash_equals`, but re-implements audited code.

**Suggested path:** adopt `symfony/security-csrf` for the token primitives + storage abstraction, and build the Quiote-specific glue (two middlewares + FPF injection + config). Best security-per-effort and consistent with the existing Symfony-component footprint.
