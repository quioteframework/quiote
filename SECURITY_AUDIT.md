# Quiote Framework — Security Audit

**Date:** 2026-06-30
**Scope:** Framework source in `src/` (the Quiote PHP MVC framework). Application code built *on* the framework is out of scope except where the framework's defaults determine an app's security posture.
**Method:** Five parallel source reviews — (1) code-execution/injection sinks, (2) XML/XXE/XSLT, (3) auth/session/cookies/CSRF/crypto, (4) output/XSS/headers/redirects, (5) filesystem/path-traversal/cache. Headline findings were manually re-verified against the source.

## Important environment context

`composer.json` requires **PHP ≥ 8.5**. On PHP 8 / libxml ≥ 2.9, external-entity loading is **off by default** and `libxml_disable_entity_loader()` is a deprecated no-op. This materially lowers the severity of "classic" XXE: the framework relies on the safe default rather than hardening explicitly, but no reviewed path re-enables external entities (`LIBXML_NOENT` / `resolveExternals` / `LIBXML_DTDLOAD`) on attacker-reachable input.

Two over-arching trust boundaries shape almost every finding:

- **`core.config_dir` and `core.cache_dir` are executed as code.** Compiled config is `include`d (and, on the APCu path, `eval`'d) verbatim. Anything that can write there, or influence which file is compiled, is RCE.
- **Most dynamic behavior is config-driven, not request-driven.** `new $class`, `eval`, dynamic `include`, `call_user_func` overwhelmingly operate on developer-authored config — by design. The exceptions (where request data reaches a sink) are called out explicitly below.

---

## Findings summary

| # | Finding | Severity | Request-reachable? |
|---|---------|----------|--------------------|
| 1 | Module/action names reach `include`/`require` & dynamic class load with no traversal sanitization | **High** (conditional) | Yes, if app uses `{module}`/`{action}` route placeholders |
| 2 | Config-cache files `chmod`'d to the cache **directory's** permission bits → world-writable executable PHP | **High** | No (local), but framework-propagated |
| 3 | Shipped authentication bypass: `QUIOTE_TEST_FORCE_AUTH` env hook in production `SecurityMiddleware` (+ dormant `TestAuthInjectionMiddleware`) | **High** | Via env var / wiring, not raw HTTP |
| 4 | No session-ID regeneration on login → session fixation | **High** | Yes |
| 5 | No CSRF protection anywhere in the framework | **High** | Yes |
| 6 | `CookieSerializer` emits cookies with no default `Secure`/`HttpOnly`/`SameSite` | **High** | n/a (default posture) |
| 7 | Config XSLT pipeline calls `registerPHPFunctions()` with no allow-list (arbitrary PHP from a stylesheet) | **Medium** (High if XSL influenceable) | No (config-trusted) |
| 8 | Auth state re-promoted on read + fail-open "don't downgrade" bias | **Medium** | Indirect |
| 9 | No automatic output escaping (manual/per-template only) | **Medium** (design) | Yes (app templates) |
| 10 | Open redirect via `setRedirect()` + `Host`-header-derived base href | **Medium** | Yes, if app forwards user input to redirect |
| 11 | Divergent fail-open legacy `SecurityService` + world-readable debug write in active one | **Medium** | No |
| 12 | No `LIBXML_NONET` on any XML/XSLT/XInclude/schema load (defense-in-depth) | **Low** | No (config-trusted) |
| 13 | No default security headers; `nosniff` explicitly disabled | **Low/Info** | n/a |
| 14 | `Set-Cookie` value concatenated without URL-encoding | **Low** | Yes, if app stores user input in cookie |
| 15 | `X-Quiote-Validation-Errors` header / error reflection — information disclosure | **Low** | Yes |
| 16 | Postgres session storage uses `addslashes()` instead of parameterized queries | **Low** | Constrained by PHP session-id charset |
| 17 | Gettext plural-forms `eval()`, cache `unserialize()`, dead `mysql_*` storages | **Low/Info** | No |

---

## High severity

### 1. Path traversal of module/action names into `include`/`require` and dynamic class load
**Severity: High (conditional on routing configuration)**
**Files:** `src/Controller/QuioteController.php:192-201, 221-222, 263-273, 299-323`; `src/Util/QuioteToolkit.php` (`canonicalName`, `expandVariables`); `src/Middleware/RoutingMiddleware.php:28-30`; `src/Execution/ActionDescriptor.php`

Module and action names flow from the matched route straight into filesystem paths and dynamic class names. The only normalization is `QuioteToolkit::canonicalName()`, which is:

```php
public static function canonicalName($name) { return str_replace('.', '/', $name); }
```

It does **not** strip `..` (and turns `.` into `/`). The module-config include uses the raw name with no guard:

```php
// QuioteController.php:192/201/221-222
if (is_readable(QuioteConfig::get('core.module_dir').'/'.$moduleName.'/Config/module.xml')) { include_once(...); }
...
$moduleConfig = QuioteConfig::get('core.module_dir').'/'.$moduleName.'/Config.php';
if (is_readable($moduleConfig)) { require_once($moduleConfig); }
```

The action/view paths add only a *leading*-slash guard (`!str_starts_with($actionName, '/')`, lines 273/378) — embedded `../` is not blocked — before `include_once $file` (line 323).

**Exploitability:** Request-reachable **only if the application defines routes with `{module}`/`{action}` placeholders** (a common Quiote pattern). Symfony's default placeholder regex `[^/]+` blocks literal `/` but not `..`, and `%2e%2e` decodes to `..`. Mitigating factors: action loading also requires a matching class to exist after include, and the `Action.php`/`View.php` suffix is appended — so direct `/etc/passwd` inclusion is blocked, but inclusion of an attacker-placed/predictable `*.php` (upload dir, log, temp) is the realistic LFI→RCE path. Where module/action come only from static route defaults, this is developer-controlled (Info).

**Remediation:** Validate each segment against `^[A-Za-z0-9_]+$` (reject `..` and null bytes) at the routing boundary *before* path construction; canonicalize with `realpath()` and assert containment within `core.module_dir`; confirm the module is enabled before any module-scoped `require`/`include`.

### 2. Config-cache files inherit the cache directory's permission bits → world-writable PHP
**Severity: High** (Critical on a `0777` cache dir)
**File:** `src/Config/QuioteConfigCache.php:543-567`; `src/Util/QuioteToolkit.php:96-110` (`mkdir`)

```php
$detectedPerms = @fileperms($baseCacheDir);
$perms = ($detectedPerms === false) ? (0777 & ~umask()) : ($detectedPerms ^ 0x4000); // strip S_IFDIR, keep the dir's perm bits
QuioteToolkit::mkdir($cacheDir, $perms);
...
chmod($cache, $perms);   // applied to a FILE that is later include()'d / eval()'d
```

The compiled cache file's mode is copied from the cache **directory's** mode (`fileperms ^ 0x4000` clears the directory type bit). Verified: a `0777` dir yields `0777` files; `0755` yields `0755`. These files are not data — they are PHP `include`d by `QuioteConfigCache::load()` (lines 402-404) and `eval`'d on the APCu warmup path. A world-writable included-PHP file means any local user/process that can write the cache dir gets arbitrary PHP execution as the web user. `QuioteToolkit::mkdir()` deliberately `chmod`s past `umask`, propagating the insecure mode to the created `config/` subdir.

**Exploitability:** Not request-reachable; requires local write access to the cache dir (co-tenant, sidecar process, or the very common `chmod -R 777 cache`). The framework actively *propagates* an unsafe directory mode onto executable files instead of enforcing a safe one.

**Remediation:** Use a fixed safe file mode (`0644`, masked by `umask`); never derive file perms from the directory and never group/other-write an included-PHP file. As a minimum, `$perms &= 0666`. Prefer `rename`-only (drop the symlink-following `copy` fallback on non-Windows) and `chmod` the temp file *before* the rename so the destination never briefly has default perms (closes the TOCTOU/symlink window that exists when the dir is writable).

### 3. Shipped authentication bypasses in production code paths
**Severity: High**
**Files:** `src/Middleware/SecurityMiddleware.php:46-55`; `src/Middleware/TestAuthInjectionMiddleware.php`

The production security middleware reads an environment variable on **every request** and force-authenticates the user:

```php
if (getenv('QUIOTE_TEST_FORCE_AUTH')) {
    $u = $this->controller->getContext()->getUser();
    if (method_exists($u, 'setAuthenticated')) { $u->setAuthenticated(true); }
}
```

Separately, `TestAuthInjectionMiddleware` authenticates anyone supplying an `__auth` request attribute.

**Exploitability:**
- `QUIOTE_TEST_FORCE_AUTH` is **live code** in the main pipeline. If that env var is ever present in a non-test environment (CI image promoted to prod, leaked/inherited Docker env, ops mistake), authentication is globally bypassed. This is a shipped backdoor.
- `TestAuthInjectionMiddleware` is currently **dormant** — it has no `#[QuioteMiddleware]` attribute, so it is not auto-registered into the default pipeline. It becomes a full pre-auth bypass the moment it is wired in (and `MiddlewareCatalog::isEnabled()` defaults unknown classes to enabled). `__auth` is not settable from raw HTTP today, but any middleware/route mapping a header/param to a request attribute would expose it.

**Remediation:** Remove both from production autoload, or hard-gate them behind a compile-time/`QuioteConfig` testing flag that fails closed in production. Never read an env var as an authentication source. (The test suite uses `QUIOTE_TEST_FORCE_AUTH`; replace with a test-only bootstrap that injects auth through the test harness, not framework runtime code.)

### 4. No session-ID regeneration on authentication — session fixation
**Severity: High**
**Files:** `src/User/QuioteSecurityUser.php:263-278` (`setAuthenticated`); `src/Storage/QuioteSessionStorage.php` (no `session_regenerate_id` anywhere in `src/`)

`setAuthenticated(true)` flips the auth flag and persists it into the *existing* session; the session ID is never rotated, and there is no privilege-change hook. An attacker who can fix a known session ID in the victim's browser (subdomain cookie, pre-auth `Set-Cookie`, non-HttpOnly XSS) retains a valid authenticated session after the victim logs in.

**Remediation:** Regenerate the session ID (`session_regenerate_id(true)`, deleting the old session) inside `setAuthenticated(true)` and on any credential/role elevation. Expose a backend-agnostic `regenerate()` on the storage layer. This is a framework responsibility — apps cannot retrofit it without subclassing the user.

### 5. No CSRF protection
**Severity: High**
**Files:** none — no `csrf`/`xsrf`/anti-forgery token mechanism exists; `FormPopulationMiddleware` / `ValidationMiddleware` neither emit nor verify a token.

State-changing requests are dispatched with no synchronizer token, double-submit cookie, or Origin/Referer check. The default session cookie is `SameSite=Lax` (see Finding 6 context), which does not fully cover cross-site top-level POSTs or same-site sub-origins.

**Remediation:** Ship a first-class CSRF token validator integrated with the form-population/validation layer, defaulting **on** for unsafe methods (POST/PUT/PATCH/DELETE). At minimum, document CSRF as an explicit application responsibility with a recommended pattern.

### 6. Insecure-by-default application cookies
**Severity: High**
**File:** `src/Http/CookieSerializer.php:73-91`

Each cookie attribute is emitted only when the caller explicitly opts in:

```php
if (!empty($values['secure']))   { $cookieStr .= '; Secure'; }
if (!empty($values['httponly'])) { $cookieStr .= '; HttpOnly'; }
if (!empty($values['samesite'])) { $cookieStr .= '; SameSite=...'; }
```

Application cookies set through the web response (auth/remember-me/preferences) therefore ship with **no `Secure`, no `HttpOnly`, no `SameSite`** by default. (The *session* cookie is hardened separately in `QuioteSessionStorage` — `Secure` defaults true, `SameSite=Lax` — so the gap is specifically the general cookie path.)

**Remediation:** Default `HttpOnly=true` and `SameSite=Lax` (configurable), and `Secure=true` when the request is HTTPS; require explicit opt-out rather than opt-in.

---

## Medium severity

### 7. Config XSLT pipeline enables arbitrary PHP from stylesheets
**Severity: Medium** (High if an XSL path or `<?xml-stylesheet?>` PI is ever attacker-influenceable)
**File:** `src/Config/QuioteXmlConfigParser.php:618-620`

```php
$proc = new QuioteXsltProcessor();
$proc->registerPHPFunctions();          // no allow-list — enables php:function(...) for ALL PHP
$proc->importStylesheet($xsl);
```

Any imported config stylesheet can call arbitrary PHP. Stylesheets are developer-authored config today (trusted), so real-world risk is low — but it is RCE-grade if an app ever compiles config from a writable/uploaded location or merges untrusted config fragments. **Positive:** the runtime view renderer `QuioteXsltRenderer` correctly uses a plain `XSLTProcessor` with no `registerPHPFunctions()`.

**Remediation:** Pass an allow-list to `registerPHPFunctions([...])`, or drop the call if config XSLs need no PHP callbacks. Document that config/XSL paths must never be attacker-controllable.

### 8. Read-side auth re-promotion and fail-open "don't downgrade" bias
**Severity: Medium**
**File:** `src/User/QuioteSecurityUser.php:210-232` (`isAuthenticated`), `327-358` (`shutdown`)

`isAuthenticated()` lazily re-reads the session auth flag and **promotes** an in-memory unauthenticated user back to authenticated if storage holds `true`; `shutdown()` deliberately avoids persisting a downgrade when storage already holds `true` and there was no explicit logout. The system is biased toward staying authenticated, so any code path that recreates the user without setting `logoutIntent` inherits the prior authenticated state. Chains with Finding 4 (fixation) to make auth both fixable and sticky.

**Remediation:** Treat in-memory auth state as canonical for the request: load once in `initialize()`, remove the read-side promotion in `isAuthenticated()`, and bind the stored flag to a server-side identity + freshly regenerated SID so a copied session value cannot resurrect auth.

### 9. No automatic output escaping
**Severity: Medium (framework design gap; High impact in apps that forget to escape)**
**File:** `src/Renderer/QuiotePhpRenderer.php:79-158` (`extract()` + `require` template, no escaping wrapper)

The default PHP renderer exposes attributes to templates with no auto-escaping and no injected escaper; escaping is manual per output. Any request-derived attribute echoed unescaped is an XSS sink (application-dependent). This is the documented Quiote model, but it is a sharp edge — compounded by the absence of `nosniff`/CSP (Finding 13). The PHPTAL renderer escapes by default.

**Remediation:** Provide an escaping helper (`$this->escape()`) and/or an opt-in auto-escaping renderer; document loudly. Recommend PHPTAL for new templates.

### 10. Open redirect + Host-header-derived base href
**Severity: Medium**
**Files:** `src/Middleware/DispatchMiddleware.php:190-209`; `src/Response/QuioteWebResponse.php:356-369, 1110-1117`; `src/Routing/QuioteRouting.php:250-291`; `src/Request/QuioteWebRequest.php:691-737`

`setRedirect($location, $code)` validates only the status code, never the location. An absolute attacker URL passes the `#^[^:]+://#` test and is emitted verbatim as `Location` if the app forwards user input (e.g. `?returnTo=`) to it. Separately, `getBaseHref()`/`getUrlAuthority()` derive from the `Host` header with no trusted-host allow-list, enabling host-header poisoning of generated absolute URLs (e.g. password-reset links, cache poisoning).

**Remediation:** Restrict user-derived redirect targets to same-origin/an allow-list; add a trusted-host allow-list for base-href construction.

### 11. Divergent fail-open security service + debug write in the active one
**Severity: Medium**
**Files:** `src/Execution/SecurityService.php:20`; `src/Security/SecurityService.php:21-25`

The **active** `Execution\SecurityService::decide()` writes to a predictable world-readable temp file on every unauthenticated secure-action hit:

```php
@file_put_contents(sys_get_temp_dir().'/quiote_sec_debug.log', 'Unauth '.$user::class."\n", FILE_APPEND);
```

This is debug residue in the security hot path (information leak + noise). Separately, the **legacy** `Security\SecurityService::decide()` is **fail-open** — it returns `ALLOW` when it cannot instantiate the action — whereas the active service is correctly fail-closed. A dead fail-open implementation is a footgun if anything is ever rewired to it.

**Remediation:** Remove the `quiote_sec_debug.log` write. Delete the unused `src/Security/SecurityService.php` (and orphaned `SecurityDecision`), or make it fail-closed to match. (The active middleware's fail-closed handling on action-creation / forward-descriptor failure and its forward-loop limit are correct — keep them.)

---

## Low / Informational

### 12. No `LIBXML_NONET` on any XML load (defense-in-depth)
**Severity: Low** — `src/Config/QuioteXmlConfigParser.php:395-397` (`substituteEntities = true`), `src/Config/Util/DOM/QuioteXmlConfigDomDocument.php` (`load`/`loadXml`/`xinclude`/`schemaValidate`, all `$options = 0`), `src/Renderer/QuioteXsltRenderer.php`, `src/Controller/QuioteSoapController.php:173`, `src/Util/QuioteSchematronProcessor.php:117`. All operate on developer config/templates, and PHP 8.5 defaults prevent external-entity XXE. `substituteEntities=true` on config docs allows billion-laughs-style expansion DoS, but only from developer-authored config. **Remediation:** pass `LIBXML_NONET` everywhere (cheap; blocks SSRF/network fetch should any path ever face untrusted input or re-enable external resolution); only enable `substituteEntities` where actually needed. Note: the SOAP WSDL `part` attribute is written into generated PHP (`QuioteSoapController.php:191-204`) — validate `$name` against `^[A-Za-z_]\w*$` before emitting it into code.

### 13. No default security response headers; `nosniff` deliberately off
**Severity: Low/Info** — No `X-Content-Type-Options`, `Content-Security-Policy`, `X-Frame-Options`, `Strict-Transport-Security`, or `Referrer-Policy` defaults exist in `src/`; `ContentNegotiationMiddleware` explicitly notes nosniff is disabled. Combined with Finding 9 this raises XSS/sniffing risk. **Remediation:** ship `X-Content-Type-Options: nosniff` and `X-Frame-Options: SAMEORIGIN` by default, with a configurable hook for CSP/HSTS.

### 14. `Set-Cookie` value not URL-encoded by default
**Severity: Low** — `src/Http/CookieSerializer.php:74-91` concatenates the raw value unless an `encode_callback` is supplied; a value with `;` could inject cookie attributes (PSR-7/Nyholm rejects CR/LF, which backstops response splitting). **Remediation:** URL-encode cookie values by default (or reject `;`/CTL chars).

### 15. Validation-error information disclosure
**Severity: Low** — `src/Middleware/ValidationMiddleware.php:400-405` emits `X-Quiote-Validation-Errors: base64(json(...))` (base64 prevents header injection, but leaks internal field/validator structure). Error bodies are subject to Finding 9 if a template echoes failed input. `ErrorHandlingMiddleware` correctly `htmlspecialchars`-escapes exception messages and only in debug. **Remediation:** drop the `X-Quiote-Validation-Errors` header in production.

### 16. Postgres session storage escaping
**Severity: Low** — `src/Storage/QuiotePostgresqlSessionStorage.php:140-310` uses `addslashes()` rather than `pg_escape_literal()`/parameterized queries. Constrained by PHP's session-id charset, so not readily exploitable, but `addslashes` is the wrong primitive. `QuiotePdoSessionStorage` uses prepared statements (good). **Remediation:** use `pg_query_params()`; prefer PDO storage; delete dead `mysql_*`/sqlsrv storages (removed APIs on PHP 8.5).

### 17. By-design code-execution sinks with safe trust boundaries
**Severity: Low/Info** — APCu config `eval('?>' . ...)` (`QuioteAPCuConfigCache.php:174`, several callers) executes framework-compiled PHP keyed by `md5(config path)`; gettext plural-forms `eval()` (`QuioteGettextTranslator.php:302`) is guarded by a character allow-list and only reads developer translation catalogs; cache `unserialize()` (`FileCache.php:33,40`, `QuioteAPCuConfigCache.php:417-423`) operates on framework-written cache, not client input. All are RCE-by-construction *only* if the cache/config/APCu store is attacker-writable — i.e. they chain off Findings 1 and 2. **Remediation:** none structural; pass `['allowed_classes' => false]` to the `FileCache` `unserialize` as hardening, consider a hand-written plural-form evaluator, and document that `core.cache_dir`/`core.config_dir`/APCu must be write-protected from untrusted input. No request-driven `eval`, no `unserialize` of cookies/session, no string-concatenated SQL of raw request input, and no `create_function`/`assert(string)`/`preg_replace /e` were found.

---

## Prioritized remediation roadmap

1. **Remove the shipped auth bypasses (Finding 3)** — delete/hard-gate `QUIOTE_TEST_FORCE_AUTH` and `TestAuthInjectionMiddleware`; move test auth into the test harness. *Lowest effort, highest blast radius.*
2. **Fix cache-file permissions (Finding 2)** — fixed safe mode, never derive from the directory, `rename`-only. *Closes a framework-propagated local-RCE.*
3. **Validate module/action names (Finding 1)** — allow-list + `realpath` containment before any include/class load.
4. **Session security (Findings 4, 8)** — regenerate SID on auth; remove read-side auth promotion.
5. **CSRF + secure cookie defaults (Findings 5, 6)** — ship a CSRF validator on by default; default `HttpOnly`/`SameSite`/`Secure`.
6. **Harden config XSLT (Finding 7)** — allow-list `registerPHPFunctions`.
7. **Defaults & defense-in-depth (Findings 10–16)** — trusted-host/redirect allow-lists, security headers (`nosniff`), `LIBXML_NONET`, cookie encoding, remove the debug write and the dead fail-open service, Postgres parameterized queries.

### What's working well
Fail-closed handling on action-creation and forward-descriptor failure, the forward-loop limit, prepared statements in the PDO session storage, the primary `random_bytes` request-ID path, the hardened *session* cookie defaults, the allow-listed content-negotiation/MIME path (the new `MimeTypeRegistry` is **not** an injectable sink), `htmlspecialchars`-escaped debug error output, and the runtime XSLT renderer correctly *not* enabling PHP functions.
