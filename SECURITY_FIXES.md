# Security Fixes — applied on branch `security-fixes`

Companion to `SECURITY_AUDIT.md`. Each item references the audit finding number. Full test suite green (1204 tests) and order-independent after these changes.

| # | Finding | Action taken |
|---|---------|--------------|
| 1 | Module/action path traversal | **Assessed — not exploitable in your app** (see below). No code change. |
| 2 | World-writable cache files | Fixed |
| 3 | Shipped auth bypasses | Fixed (removed) |
| 4 | Session fixation | Fixed (native regeneration on login) |
| 5 | No CSRF | Implemented (core) with `symfony/security-csrf` — see `docs/CSRF_PLAN.md` |
| 6 | Insecure cookie defaults | Fixed (secure-by-default) |
| 7 | XSLT `registerPHPFunctions` | Fixed (allow-list, default none) |
| 8 | Read-side auth re-promotion | Fixed |
| 10 | Open redirect / Host trust | Fixed (trusted-hosts allow-list) |
| 11 | Debug write + dead fail-open service | Fixed (removed both) |
| 12 | No `LIBXML_NONET` | Fixed — **see correction below (NOT `LIBXML_NOENT`)** |
| 13 | No `nosniff` | Fixed (nosniff default; no X-Frame-Options) |
| 14 | Cookie value not encoded | Fixed |
| 15 | Validation-error info disclosure | Fixed (gated, off by default) |
| 16 | Non-PDO drivers | Fixed (removed raw non-PDO drivers/storages) |

> Findings #9 (no auto output-escaping) and #17 (by-design `eval`/`unserialize` under the cache/config trust boundary) were intentionally left as documented design/trust-boundary items.

---

## #1 — Module/action path traversal: not exploitable as your routes are written

Your routes use **static** `_module`/`_action` defaults, e.g.:

```php
$routeCollection->add('asn.index', new Route('/shipment', [
    '_module' => 'Order', '_action' => 'Asn.Index',
], []));
```

There is no `{module}`/`{action}` placeholder anywhere, so `_module`/`_action` are compile-time constants emitted by the route generator — never derived from the request path, query, or body. Symfony's `UrlMatcher` returns exactly the literal defaults you registered. The traversal vector in the audit required an app to funnel **user-controlled** input into `_module`/`_action` (a wildcard `{module}` placeholder or a custom catch-all copying a query param). Your routing does not do that, so the finding is **not request-reachable** here.

It remains a latent foot-gun only if a future route introduces a user-controlled module/action segment. If you want belt-and-suspenders, the cheap defense-in-depth is to validate each segment against `^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$` (allows your dotted `Asn.Index`, rejects `..`/`/`/null bytes) inside `QuioteController::createActionInstance()`/`checkActionFile()`. Not applied now to avoid touching the hot path unnecessarily — say the word and I'll add it.

## #12 — Correction: `LIBXML_NONET`, not `LIBXML_NOENT`

Your note said "add `LIBXML_NOENT`". I did **not** do that, on purpose: `LIBXML_NOENT` *enables* entity substitution and is precisely the flag that turns on XXE — adding it would introduce the vulnerability the finding warns about. The correct hardening (and what the finding recommended) is `LIBXML_NONET`, which *disables* network access during parsing. I added `LIBXML_NONET` to the central `load()`/`loadXml()`/`xinclude()` paths in `QuioteXmlConfigDomDocument`. If you specifically need entity *substitution* somewhere, that should be a narrow, explicit opt-in on trusted input only — not a global default.

---

## Details of each fix

**#2 cache perms** (`src/Config/QuioteConfigCache.php`) — cache files are now `chmod`'d to an owner-focused, umask-respecting mode `((0644 & ~umask()) | 0600)` **independent of the directory mode**, instead of inheriting the directory's bits via `fileperms ^ 0x4000`. The temp file is `chmod`'d before the atomic rename so the published file never briefly has surprising perms. A `0777` cache dir no longer yields world-writable executable PHP.

**#3 auth bypasses** — removed the `QUIOTE_TEST_FORCE_AUTH` env hook from `SecurityMiddleware::process()` and deleted `src/Middleware/TestAuthInjectionMiddleware.php`. (Tests only ever *unset* the env var, so nothing depended on it.)

**#4 session fixation** (`src/Storage/QuioteSessionStorage.php`, `src/User/QuioteSecurityUser.php`) — added a native `QuioteSessionStorage::regenerate(bool $deleteOld = true)` wrapping `session_regenerate_id()` (preserves `$_SESSION`). `QuioteSecurityUser::setAuthenticated(true)` calls it **only on the unauthenticated→authenticated transition**. This is the framework equivalent of the custom rotation in `../jakamo`'s `SessionManager::regenerate()` (which is a bespoke PSR-7 array-session manager and not directly portable); apps with custom storages override `regenerate()`.

**#6 / #14 cookies** (`src/Response/QuioteWebResponse.php`, `src/Http/CookieSerializer.php`) — default `HttpOnly=true` and `SameSite=Lax`; `Secure` defaults to the request's actual HTTPS state, detected via a `requestIsHttps()` helper that works for both an `QuioteWebRequest` (`isHttps()`) and a raw PSR-7 request (URI scheme, then `HTTPS`/`REQUEST_SCHEME` server params, then `X-Forwarded-Proto`) — `method_exists('isHttps')` alone is request-*type* detection, not scheme detection, and would have wrongly left cookies non-Secure on the PSR-7/HTTPS path; values URL-encoded by default (`CookieSerializer` falls back to `rawurlencode` when no callback). Apps opt out explicitly per call.

**#7 XSLT** (`src/Config/QuioteXmlConfigParser.php`) — `registerPHPFunctions()` is now called only with an explicit allow-list from `core.config_xsl_allowed_php_functions` (string or array). Default null/empty ⇒ **register nothing** (no `php:function` exposure). Verified the shipped stylesheets compile fine with none registered.

**#8 auth re-promotion** (`src/User/QuioteSecurityUser.php`) — `isAuthenticated()` no longer re-reads storage to "rehydrate"/promote; the in-memory state loaded once in `initialize()` is canonical for the request.

**#10 Host trust** (`src/Request/QuioteWebRequest.php`) — opt-in `core.trusted_hosts` (array of exact hostnames and/or `/regex/`). A `Host` matching none is replaced with the first literal trusted host, neutralizing host-header poisoning of generated absolute URLs/redirects. Empty/unset preserves prior behavior (set it in production).

**#11** (`src/Execution/SecurityService.php`, `src/Security/*`) — removed the `quiote_sec_debug.log` world-readable write; deleted the unused, fail-open `src/Security/SecurityService.php` and `src/Security/SecurityDecision.php` (no references).

**#13 nosniff** (`src/Middleware/DispatchMiddleware.php`) — sends `X-Content-Type-Options: nosniff` by default (config `core.send-nosniff-header`, only when not already set). Deliberately **no** `X-Frame-Options` so external framing stays allowed.

**#15 validation errors** (`src/Middleware/ValidationMiddleware.php`) — the `X-Quiote-Validation-Errors` header is now gated behind `core.expose_validation_errors_header` (default **false**).

**#16 PDO-only** — deleted the raw non-PDO database drivers (`QuioteMysqlDatabase`, `QuioteMysqliDatabase`, `QuiotePostgresqlDatabase`, `QuioteSqlsrvDatabase`, `QuioteZendclouddocumentserviceDatabase`) and the legacy raw session storages (`QuioteMysqlSessionStorage`, `QuiotePostgresqlSessionStorage` [the `addslashes` one], `QuioteSqlsrvSessionStorage`, `QuioteWindowsazureSessionStorage`). Kept `QuiotePdoDatabase` + `QuiotePdoSessionStorage` + the ORM integrations (`Doctrine*`, `Propel`), which sit on PDO and are not raw-SQL security risks. The only cross-references were among the deleted files themselves; no tests or configs referenced them.

---

## Follow-up: `getRequest()` always returns an `QuioteWebRequest`

`QuioteContext::getRequest()` previously could return either an `QuioteWebRequest`
(which `extends Nyholm\Psr7\ServerRequest`) or a bare `Nyholm\Psr7\ServerRequest`,
depending on what `setRequest()` was handed by the pipeline (SlotMiddleware,
ValidationMiddleware) or tests — the latter lacks Quiote helpers such as `isHttps()`
and caused the original `Call to undefined method …::isHttps()` fatal. `setRequest()`
now normalizes any foreign PSR-7 request into an `QuioteWebRequest` via a new
`QuioteWebRequest::fromPsr()` adapter (preserving method, URI, headers, body,
protocol, server params, cookies, query, uploaded files, parsed body and
attributes). `getRequest()` is therefore consistently an `QuioteWebRequest`, so
HTTPS/scheme detection (and the cookie `Secure` default) is reliable on every path;
the `requestIsHttps()` helper remains as a safety net.

## New configuration directives introduced

| Directive | Default | Finding |
|-----------|---------|---------|
| `core.config_xsl_allowed_php_functions` | `null` (none) | #7 |
| `core.trusted_hosts` | `[]` (no restriction) | #10 |
| `core.send-nosniff-header` | `true` | #13 |
| `core.expose_validation_errors_header` | `false` | #15 |
| `cookie_httponly` (default) | `true` (was `false`) | #6 |
| `cookie_samesite` (default) | `Lax` (was `null`) | #6 |
