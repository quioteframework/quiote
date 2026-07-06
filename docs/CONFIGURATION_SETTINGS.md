# Configuration Settings Reference

A systematic catalog of every configuration setting supported by Quiote's middleware
and core components, and exactly how to set each one (`settings.xml`/`.php`/`.yaml`,
`factories.xml`, an environment variable, or plain constructor arguments with no config
file involved at all). Assembled by reading each class's actual `Config::get()`/
`getParameter()`/`getenv()` call sites — where a class has no configuration, that's
stated explicitly rather than omitted, so absence of a setting here means it was
verified absent, not unresearched.

Contents:
1. [Middleware Pipeline](#configuration-settings-reference--middleware-pipeline) — `RoutingMiddleware`, `OutputTypeSyncMiddleware`, `DispatchMiddleware`, dead/unused middleware files, `HttpMethodMapper`
2. [Core Bootstrap, Context, Storage, Logging, Database](#configuration-settings-reference--core-bootstrap-context-storage-logging-database)
3. [Request-Intake Middleware](#configuration-settings-reference--request-intake-middleware) — error handling, sessions, timing/trace, payload parsing, content negotiation
4. [Slots, Assets, Forms, and the Middleware Catalog](#configuration-settings-reference--slots-assets-forms-and-the-middleware-catalog) — including the `<middleware_config>` enable/disable mechanism and the `MiddlewarePipeline` default stack order
5. [Runtime, Request/Response, Cache, Console, I18n/Translation](#configuration-settings-reference--runtime-requestresponse-cache-console-i18ntranslation)
6. [CSRF, Security, Validation, Routing, User, DI](#configuration-settings-reference--csrf-security-validation-routing-user-di)
7. [Telemetry](#configuration-settings-reference--telemetry) — `Quiote\Telemetry\Trace`, the `telemetry.*` settings family

---
---

# Configuration Settings Reference — Middleware Pipeline

This section catalogs every configuration setting read by the core PSR-15 middleware
classes in `Quiote/Middleware/` and the routing helper `Quiote\Execution\HttpMethodMapper`.

Recap of the config mechanisms referenced below:

- **`Config::get($key, $default)`** reads from the global static `Quiote\Config\Config`
  store, populated from `settings.xml` (compiled by `Quiote\Config\SettingConfigHandler`)
  or the equivalent `settings.php`/`settings.yaml` flat dot-keyed array.
- A bare `<settings>` block compiles keys under the `core.` prefix
  (`<setting name="foo">v</setting>` → `core.foo`). A `<settings prefix="routing.">`
  wrapper overrides the prefix for its children (`<setting name="bar">v</setting>` →
  `routing.bar`).
- `<setting name="x">` with nested `<ae:parameter name="a">1</ae:parameter>` children
  compiles to an associative array value instead of a scalar.
- **`getParameter`/`hasParameter`** (from `Quiote\Util\ParameterHolder`) is a *different*
  mechanism used by factory-configured objects (e.g. `factories.xml`, or per-output-type
  configuration) — not the global `Config` store. It's called out explicitly below where
  it applies, so it isn't confused with `Config::get`.
- Where a class takes a plain constructor argument with no config file involvement, that
  is stated explicitly.

---

### RoutingMiddleware

`Quiote/Middleware/RoutingMiddleware.php` — executes Quiote routing (`Routing::match()`)
against the incoming request path and attaches `module`/`action`/`output_type` and an
`ActionDescriptor` to PSR-7 request attributes.

**Configuration: none.** This class contains no `Config::get`/`getParameter` calls at
all. Its only "default" is a literal fallback output type of `'html'` in code
(`$attributes['_output_type'] ?? $preNegotiated ?? 'html'`), not a configurable setting.

- Constructor: `__construct(private readonly Routing $routing, private readonly Controller $controller)`
  — both are plain object dependencies, wired in via DI/`Context`, not config-file driven.
- Default pipeline instantiation (`MiddlewarePipeline::doBuild()`):
  ```php
  $routing = $context->getRouting();
  $controller = $context->getController();
  $construct(RoutingMiddleware::class, fn() => new RoutingMiddleware($routing, $controller));
  ```

Note: this middleware calls `Quiote\Execution\HttpMethodMapper::toActionMethod($httpMethod)`,
which itself reads the `routing.http_method_map` setting — see the dedicated section below.

---

### OutputTypeSyncMiddleware

`Quiote/Middleware/OutputTypeSyncMiddleware.php` — after routing resolves (or overrides)
the output type, re-syncs the `Controller`'s internal output-type selection to match the
`output_type` PSR request attribute (via `$controller->getOutputType($attr)`).

**Configuration: none.** No `Config::get`/`getParameter` calls in this file.

- Constructor: `__construct(private readonly Controller $controller)` — plain object
  dependency, no config file involvement.
- Default pipeline instantiation:
  ```php
  $construct(OutputTypeSyncMiddleware::class, fn() => new OutputTypeSyncMiddleware($controller));
  ```

---

### DispatchMiddleware

`Quiote/Middleware/DispatchMiddleware.php` — replaces the legacy filter chain/dispatch
filter. Runs actions (simple and non-simple) through the container-less `ActionExecutor`,
handles action/view caching, and builds the final PSR-7 response (headers, redirects,
cookies).

| Key | Default | How to set | What it controls |
|---|---|---|---|
| `core.disable-framework-headers` | `false` | `settings.xml` under `core.` | If truthy, skips the entire framework response-header block below (both the cache-hit header and the nosniff header are never added). |
| `core.cache-hit-header` | `'X-Quiote-Cache-Hit'` | `settings.xml` under `core.` | Header name sent (with value `'1'`) when a cache hit occurred. Set to an empty/falsy value to suppress the header (still requires `core.disable-framework-headers` to be false and `$cacheHit` to be true). |
| `core.send-nosniff-header` | `true` | `settings.xml` under `core.` | Whether `X-Content-Type-Options: nosniff` is auto-added when not already present on the response. Set to `false` to opt out. |
| `core.cache_enabled` | `false` | `settings.xml` under `core.` | Master switch for action/view caching. Read independently in both `processSimple()` and `processNonSimple()`. When false, no cache lookup/store happens for that request path regardless of `core.use_cache`. |
| `core.use_cache` | `false` | `settings.xml` under `core.` | Gates whether an `ActionViewCache` instance is actually constructed (`new ActionViewCache(CacheManager::getCache())`) to service the request. In `processSimple()` this is ANDed with `core.cache_enabled` (`$cacheEnabled && Config::get('core.use_cache', false)`); in `processNonSimple()` it's only read once `core.cache_enabled` is already true. Functionally both call sites require *both* settings to be true for caching to actually engage. |

Exact source lines (for reference):
```php
$disableHeaders = (bool)Config::get('core.disable-framework-headers', false);
$cacheHitHeader = Config::get('core.cache-hit-header', 'X-Quiote-Cache-Hit');
Config::get('core.send-nosniff-header', true)
$cacheEnabled = (bool)Config::get('core.cache_enabled', false);
$useCache = $cacheEnabled && \Quiote\Config\Config::get('core.use_cache', false); // processSimple
$useCache = \Quiote\Config\Config::get('core.use_cache', false);                  // processNonSimple
```

Example `settings.xml`:
```xml
<settings>
  <setting name="disable-framework-headers">false</setting>
  <setting name="cache-hit-header">X-Quiote-Cache-Hit</setting>
  <setting name="send-nosniff-header">true</setting>
  <setting name="cache_enabled">true</setting>
  <setting name="use_cache">true</setting>
</settings>
```

**Not a `Config` key — separate mechanism:** `DispatchMiddleware::buildPsrResponse()` also
calls `$ot->getParameter('http_headers', [])` on the resolved `OutputType` object:
```php
$httpHeaders = $ot->getParameter('http_headers', []);
```
This is an output-type-level parameter (via `Quiote\Util\ParameterHolder`, typically
configured wherever output types themselves are defined/factory-configured, e.g. as
`<ae:parameter name="http_headers">` entries under that output type's own config block),
*not* a `Config::get('core....')` setting. Default `[]` (no extra headers). Each
key/value in the array becomes a response header (`$resp->withHeader($name, $value)`).

- Constructor: `__construct(private readonly Controller $controller)` — plain object
  dependency; internally and unconditionally builds `new ActionExecutor($controller)`
  (no config choice here — the legacy container-based path has been removed entirely).
- Default pipeline instantiation:
  ```php
  $construct(DispatchMiddleware::class, fn() => new DispatchMiddleware($controller));
  ```

---

### ActionExecutionMiddleware

`Quiote/Middleware/ActionExecutionMiddleware.php` — **dead file**. Its entire contents are:
```php
<?php // removed legacy ActionExecutionMiddleware
```
No class is defined. **Configuration: not applicable** — there is no class to configure,
and it is not referenced in `MiddlewarePipeline::doBuild()`.

---

### FinalizeMiddleware

`Quiote/Middleware/FinalizeMiddleware.php` — scaffold placeholder for future
end-of-request persistence (e.g. slim session/user state, metrics flush). Currently a
pure passthrough: it calls `$handler->handle($request)` and returns the response
unmodified.

**Configuration: none.** No `Config::get`/`getParameter` calls; fully hardcoded no-op
behavior today.

- Constructor: `__construct(private readonly Controller $controller)` — plain object
  dependency, no config file involvement.
- **Not part of the default pipeline.** `FinalizeMiddleware` is never constructed or
  added in `MiddlewarePipeline::doBuild()` — it exists in the codebase but is currently
  unused by the default stack. An app wanting it would need to register it manually
  (e.g. via `MiddlewareCatalog::register(...)`) or wire it via DI itself; there is no
  config-file switch that turns it on.

---

### MiddlewareDispatcher — removed

Was a simple stack-based (LIFO) PSR-15 middleware dispatcher, superseded by `Relay\Relay`
(used by `MiddlewarePipeline::doBuild()`) and never wired into the default request flow.
Deleted along with its identity entry in `rector.php`.

---

### HttpMethodMapper

`Quiote/Execution/HttpMethodMapper.php` — central mapping from HTTP verbs to Quiote
action method tokens (`read`/`write`/`update`/`remove`), used by `RoutingMiddleware` (via
`HttpMethodMapper::toActionMethod($httpMethod)`) to derive the `execute<Token>()` method
name to dispatch on the resolved action.

| Key | Default | How to set | What it controls |
|---|---|---|---|
| `routing.http_method_map` | `[]` (empty array — falls back entirely to the hardcoded `DEFAULT_MAP`) | `settings.xml`, **must** use a `<settings prefix="routing.">` wrapper (a bare `<settings>` block lands under `core.`, not `routing.`) | Additive/overriding entries merged on top of `DEFAULT_MAP` (`GET/HEAD/OPTIONS/TRACE → read`, `POST → write`, `PUT/PATCH → update`, `DELETE → remove`). Verb keys are matched case-insensitively; token values are lowercased and become the suffix of `execute<Token>()` (ucfirst-ed) that must exist on the action class. |

Exact source line:
```php
$overrides = Config::get('routing.http_method_map', []);
```

Exact XML syntax (nested `ae:parameter` children, from the class's own docblock):
```xml
<settings prefix="routing.">
  <setting name="http_method_map">
    <ae:parameter name="PATCH">write</ae:parameter>
    <ae:parameter name="LOCK">lock</ae:parameter>
  </setting>
</settings>
```
Also settable programmatically: `Config::set('routing.http_method_map', ['LOCK' => 'lock']);`

`HttpMethodMapper` is a `final class` with only static methods — it is never instantiated
and has no constructor.

---

### Default pipeline construction reference (`MiddlewarePipeline.php`)

For context, the exact construction lines for the classes above, from
`MiddlewarePipeline::doBuild()`:

```php
$routing = $context->getRouting();
$controller = $context->getController();

$construct(RoutingMiddleware::class, fn() => new RoutingMiddleware($routing, $controller));
$construct(OutputTypeSyncMiddleware::class, fn() => new OutputTypeSyncMiddleware($controller));
$construct(DispatchMiddleware::class, fn() => new DispatchMiddleware($controller));
```

`ActionExecutionMiddleware` (dead file) and `FinalizeMiddleware` have no corresponding
entries in `doBuild()` — they are not part of the default pipeline. `MiddlewareDispatcher`
and `JsonBodyParsingMiddleware` have been removed entirely (see their sections above/below).
`HttpMethodMapper` is never constructed anywhere (static utility invoked from within
`RoutingMiddleware::process()`).

---
---

# Configuration Settings Reference — Core Bootstrap, Context, Storage, Logging, Database

This section catalogs every configuration setting supported by: the core bootstrap
(`Quiote/Quiote.php`), the `Config` store itself, `Context`, the `Storage` classes, the
`Logging` sinks, and the `Database` classes. It does not attempt to catalog every setting
consumable via `settings.xml` across the whole framework — only the ones actually read by
the files covered here.

Three distinct "configuration" mechanisms are documented below:

1. **`core.*` keys read via `Quiote\Config\Config::get()`/`has()`** — set either
   by `settings.xml`/`settings.php`/`settings.yaml` (compiled to a flat
   `core.`-prefixed dot-keyed map by `SettingConfigHandler`), or hardcoded /
   derived directly in bootstrap code (`Quiote.php`, `version.php`).
2. **Factory parameters** (`getParameter()`/`hasParameter()`, from
   `Quiote\Util\ParameterHolder`) — populated from `<ae:parameter>` children of
   a `factories.xml` (or `databases.xml`) entry, passed into a class's
   `initialize($context/$databaseManager, $parameters)`.
3. **Logging sinks** — configured purely in PHP (constructor arguments),
   wired up in application bootstrap code (typically `index.php`) via
   `Log::addSink(new XSink(...))`, `Log::setDefaultLevel()`, `Log::setLevels()`,
   BEFORE `Kernel::run()`. There is no `settings.xml` equivalent for these.

## Config mechanisms

### `Quiote\Config\Config`
The underlying global static key-value store all `core.*` (and other) settings
live in. Not a "setting" itself — the mechanism everything else uses.

| Method | Behavior |
|---|---|
| `Config::get($name, $default = null)` | Returns the stored value, or `$default` if unset. |
| `Config::has($name)` | True if the key has been set (even to `null`). |
| `Config::isReadonly($name)` | True if the key was set with `$readonly = true`. |
| `Config::set($name, $value, $overwrite = true, $readonly = false)` | Sets a value. If `$overwrite` is false, an existing value is preserved. If the key is already marked readonly, the call is a no-op (returns false) regardless of `$overwrite`. Passing `$readonly = true` locks the key going forward. |
| `Config::remove($name)` | Unsets a key, unless it's readonly. |
| `Config::fromArray(array $data)` | Bulk-imports a flat dot-keyed map (this is what compiled `settings.xml`/`.php`/`.yaml` files call). Readonly keys always win over `$data`, and `$data` wins over pre-existing non-readonly keys (`self::$readonlies + $data + self::$config`). |
| `Config::toArray()` | Dumps the whole store. |
| `Config::clear()` | Resets the store back to only its readonly keys. |
| `Config::resetWorkerState(array $preserveKeys = [])` | FrankenPHP worker-mode reset: keeps readonly keys plus any keys named in `$preserveKeys` (with special-cased `'modules'`, which preserves all `modules.*` keys) and drops everything else. |

### `Quiote\Config\SettingConfigHandler`
Compiles `settings.xml` (namespace `http://quiote.dev/quiote/config/parts/settings/1.1`) into a flat, dot-keyed PHP array passed to `Config::fromArray()`.

- `<settings><setting name="foo">value</setting></settings>` → `core.foo = 'value'`.
- `<settings prefix="xyz."> ... </settings>` overrides the default `core.` prefix for its `<setting>` children only.
- A `<setting>` with nested `<ae:parameter>` children compiles its value to an array (`getQuioteParameters()`) instead of a literal scalar.
- `<system_action name="foo"><module>M</module><action>A</action></system_action>` compiles to `actions.foo_module = 'M'` and `actions.foo_action = 'A'`.
- `settings.php`/`settings.yaml` are the same flat map, written/returned directly (e.g. `return ['core.app_name' => 'Demo'];`) — no XML-specific concepts (prefixes, `<ae:parameter>`) exist at that level, they're already resolved into the array shape.

### `Quiote\Config\FactoryConfigHandler`
Compiles `factories.xml` (namespace `http://quiote.dev/quiote/config/parts/factories/1.1`) into per-factory `class`/`params` pairs, one for each of a fixed set of factory slots (`validation_manager`, `response`, `database_manager`, `translation_manager`, `routing`, `request`, `controller`, `storage`, `user`). Only slots marked `required => true` in `getFactoryDefinitions()` are compiled/instantiated; `translation_manager` is required only if `Config::get('core.use_translation', false)` is true. Each factory element's `<ae:parameter name="x">value</ae:parameter>` children become the `$parameters` array passed to that class's `initialize($context, $parameters)`.

### `Quiote\Util\ParameterHolder`
Base class for anything that accepts factory parameters (`Storage`, `Database`, etc.). `getParameter($name, $default)`/`hasParameter($name)` read from the `$parameters` array populated by `initialize()`; both support dotted/array-path access via `ArrayPathDefinition` (e.g. `configuration[metadata_driver_impl_class]`).

---

## `Quiote\Quiote.php` (framework bootstrap)

Read in full. `Quiote::bootstrap($environment = null, $contexts = null, array $options = [])` sets up the following `core.*` config, in order:

| Key | Default / value | Overridable? | Set by |
|---|---|---|---|
| `core.minimum_php_version` | `'8.5.0'` | Set once at file-load time, before `bootstrap()` even runs (top of `Quiote.php`). Not read from env; hardcoded. | `Config::set('core.minimum_php_version', '8.5.0')` — used to `trigger_error()` (E_USER_ERROR) if the running PHP is older. |
| `core.quiote_dir` | `__DIR__` (the `Quiote/` source directory) | No — set `readonly` (4th arg `true`). | Hardcoded to the directory this file lives in. |
| `core.environment` | none (must be supplied) | Becomes readonly after being set once. If the caller passes an `$environment` argument but `core.environment` is *already* set and readonly, the existing value wins and the argument is ignored. If neither an argument nor a pre-existing `core.environment` exists, `bootstrap()` throws `QuioteException`. | `Quiote::bootstrap($environment)` argument, or a pre-existing `core.environment` value (e.g. set by the app's `settings.xml` or index.php before calling bootstrap). |
| `core.debug` | `false` | Yes (`$overwrite = false` passed to `Config::set`, so this only takes effect if not already set — e.g. by `settings.xml` loaded earlier, or by app code before `bootstrap()`). | Hardcoded default; real value typically comes from `settings.xml`/app config set before `bootstrap()` runs. |
| `core.developer_exceptions` | `false` | Yes (`$overwrite = false`). Deliberately independent of `core.debug` (see `docs/WHOOPS_ERROR_HANDLING_PLAN.md`) — controls exception response detail specifically, not general debug behavior. | Hardcoded default; override via `settings.xml`/app config before `bootstrap()`. |
| `core.app_dir` | none — **required** | N/A | Must already be set (typically by the application's front controller/`index.php`) before `bootstrap()` is called; if unset, `bootstrap()` throws `QuioteException('Configuration directive "core.app_dir" not defined...')`. |
| `core.cache_dir` | `{core.app_dir}/cache` | Set readonly. | Computed from `core.app_dir`. |
| `core.config_dir` | `{core.app_dir}/Config` | Set readonly. | Computed from `core.app_dir`. |
| `core.system_config_dir` | `{core.quiote_dir}/Config/defaults` | Set readonly. | Computed from `core.quiote_dir`. |
| `core.lib_dir` | `{core.app_dir}/Lib` | Set readonly. | Computed from `core.app_dir`. |
| `core.model_dir` | `{core.app_dir}/Models` | Set readonly. | Computed from `core.app_dir`. |
| `core.module_dir` | `{core.app_dir}/Modules` | Set readonly. | Computed from `core.app_dir`. |
| `core.template_dir` | `{core.app_dir}/Templates` | Set readonly. | Computed from `core.app_dir`. |

After these are set, `bootstrap()` loads `{core.config_dir}/settings.xml` via `ConfigCache::load()` (or `APCuConfigCache::load()` if the `\QUIOTE_USE_APCU_CONFIG_CACHE` constant is defined and truthy) — this is what actually populates the bulk of `core.*` settings via `SettingConfigHandler`/`Config::fromArray()`. If `core.debug` is true after that first load, the cache is cleared (`Toolkit::clearCache()`) and settings.xml is reloaded, so debug mode always recompiles config from source rather than trusting a stale compiled cache.

Other settings/env vars read during `bootstrap()`/`prewarm()` (not `Config::set` calls, but consumed here):

| Key / env var | Read by | Purpose |
|---|---|---|
| `\QUIOTE_USE_APCU_CONFIG_CACHE` (PHP constant, not a Config key) | `bootstrap()`, `prewarm()`, `DatabaseManager::initialize()`, `Context::initialize()` | If defined and truthy, compiled config (settings.xml, factories.xml, databases.xml) is cached in APCu instead of on-disk compiled PHP files. |
| env var `QUIOTE_APCU_PREWARM` | `bootstrap()` | If APCu caching is enabled and this env var is one of `1`/`true`/`yes`/`on` (case-insensitive), forces `$doPrewarm = true`. |
| `core.apcu_prewarm` | `bootstrap()` | Alternative to the env var above: if truthy, also forces prewarm. |
| `core.default_context` | `bootstrap()` (`self::prewarm(Config::get('core.default_context'))`), also `Context::getInstance()` | Name of the context to prewarm when no explicit context list was passed to `bootstrap()`; also the fallback profile name for `Context::getInstance(null)`. |

`Quiote::prewarm(?string $context = null)` is a no-op unless `\QUIOTE_USE_APCU_CONFIG_CACHE` is defined/true and the `APCuConfigCache` class exists and reports itself available; it then calls `APCuConfigCache::warmup([], $context)`.

`Quiote::context(?string $name = null, bool $prime = false)` is a thin convenience wrapper over `Context::getInstance($name)` — reads no config of its own beyond what `Context::getInstance()` reads.

### `Quiote/version.php` (required by `Quiote.php` at load time)
Not `core.*` — sets framework identity metadata under the `quiote.*` prefix, all hardcoded (not overridable, not read from env or settings.xml):

| Key | Value |
|---|---|
| `quiote.name` | `'Quiote'` |
| `quiote.major_version` | `'2'` |
| `quiote.minor_version` | `'0'` |
| `quiote.micro_version` | `'0'` |
| `quiote.status` | `'dev'` |
| `quiote.branch` | `'php84'` |
| `quiote.version` | Computed: `{major}.{minor}.{micro}[-{status}]` |
| `quiote.release` | Computed: `{name}/{version}` |
| `quiote.url` | `'https://github.com/jakamoltd/quiote'` |
| `quiote_info` | Computed: `{release} ({url})` |

---

## `Quiote\Context`

`Context` is the per-profile ("context") container for a request-handling pipeline (controller, storage, user, database manager, routing, etc.). Settings it reads directly via `Config::get()`/`has()`:

| Key | Default | Where read | What it does |
|---|---|---|---|
| `core.default_context` | none (throws if unset and no profile given) | `Context::getInstance($profile = null)` | Fallback context/profile name when `getInstance()` is called with no explicit name. |
| `core.context_implementation` | `static::class` (i.e. `Context` itself) | `Context::getInstance()` | Allows swapping in a `Context` subclass; the class named here is instantiated instead of the base `Context`. |
| `core.config_dir` | (see Quiote.php above) | `Context::initialize()` | Used to locate `{core.config_dir}/factories.xml`, compiled/cached via `ConfigCache::checkConfig()` or `APCuConfigCache::checkConfig()` and then `include`d — this is what populates `$this->userFactoryInfo`, `$this->routingFactoryInfo`, `$this->storageFactoryInfo`, `$this->requestFactoryInfo`, and (if database use is enabled) `$this->databaseManagerFactoryInfo`. |
| `core.use_database` | `false` | `Context::initialize()` (invariant check), `getStorage()`, `getUser()` | If true, a `databaseManagerFactoryInfo` invariant is required after `initialize()`, and `getStorage()`/`getUser()` will lazily recreate the database manager (from captured factory info) before recreating storage/user in worker mode. |
| `core.namespace_prefix` | `'App'` | `Context` model-loading logic (e.g. around line 916) | Base PHP namespace under which `Models`/`Modules\{name}\Models` classes are looked up when resolving a model name to a class. |
| `core.model_dir` | (see Quiote.php above) | `Context` model-loading fallback | Legacy (non-namespaced) file path for global model files: `{core.model_dir}/{modelName}Model.php`. |
| `core.module_dir` | (see Quiote.php above) | `Context` model-loading fallback | Legacy (non-namespaced) file path for module-scoped model files: `{core.module_dir}/{module}/Models/{modelName}Model.php`. |
| `core.use_translation` | `false` | `Context::getTranslationManager()` | Gates whether the translation manager is actually returned (checked live per-call, not just at factory-required time); returns `null` if false even if a `TranslationManager` instance exists internally. |
| `\QUIOTE_USE_APCU_CONFIG_CACHE` (PHP constant) | — | `Context::initialize()` | Same APCu-vs-file-cache switch as in `Quiote.php`, applied to `factories.xml`. |

Correlation ID: `Context` generates a per-request correlation ID (`$this->correlationId`) itself in `Context::handle()`/`setRequest()` via `random_bytes()`/`uniqid()` — **there is no configuration setting for this today**. Line 580 has an explicit TODO: `// TODO: Support configurable header name for e.g. Azure Application Gateway correlation ID` — i.e. a future setting to read the correlation ID from an inbound header (rather than always generating one) is planned but not implemented.

---

## `Quiote\Storage` (session/session-like storage, configured via `factories.xml` `storage` entry parameters)

### `Storage` (abstract base, `Quiote/Storage/Storage.php`)
Purpose: base class wiring `Context` + `ParameterHolder` parameters into any storage implementation. Declares no parameters of its own (`read`/`remove`/`shutdown`/`store` are abstract).

### `SessionStorage`
Purpose: PHP-native (`session_start()`-backed) session storage, implementing `SessionHandlerInterface` around PHP's default `SessionHandler`.

| Parameter | Default | What it does |
|---|---|---|
| `auto_start` | (documented but not read in code — no `getParameter('auto_start', ...)` call found in `startup()`; PHP's normal session auto-start behavior applies) | — |
| `session_cache_expire` | unset (PHP default from php.ini) | If present, calls `session_cache_expire($value)`. |
| `session_cache_limiter` | unset (PHP default) | If present, calls `session_cache_limiter($value)`. |
| `session_module_name` | unset (PHP default) | If present, calls `session_module_name($value)`. |
| `session_save_path` | unset (PHP default) | If present, calls `session_save_path($value)`. |
| `session_name` | `'Quiote'` | Session cookie/name, set via `session_name($value)` when no session is currently active. |
| `session_id` | unset | If set (and differs from the current session id), forces `session_id($value)` before starting the session. |
| `session_cookie_lifetime` | PHP's current `session_get_cookie_params()['lifetime']` | Passed to `session_set_cookie_params()`. Accepts either a numeric value (cast to int, seconds) or a `strtotime()`-parsable string (evaluated relative to `0`). |
| `session_cookie_path` | PHP's current cookie path, or (if that default is `/`) the routing base path via `$this->context->getRouting()->getBasePath()`. **Note:** regardless of the resolved value, the code then unconditionally forces the path to `'/'` if it isn't already `'/'` (a fix for a login-narrowed-cookie-path bug), so `session_cookie_path` is effectively overridden to `/` in practice today. | Session cookie path. |
| `session_cookie_domain` | PHP's current `session_get_cookie_params()['domain']` | Session cookie domain. |
| `session_cookie_secure` | Not set → defaults to `true` (secure-by-default, per bug #1541) regardless of whether the request is HTTPS. If explicitly set to a non-null value, cast to `bool`. If explicitly set to `null`, also behaves as `true` ("auto secure"). Additionally, if `$secure` ends up true and the request is a `WebRequest` that is not HTTPS, the code still forces `$secure = true` (i.e. there is currently no code path that ends up with a non-secure cookie over HTTP even by disabling this). | Whether the session cookie is HTTPS-only. |
| `session_cookie_httponly` | PHP's current `session_get_cookie_params()['httponly']` | Session cookie HttpOnly flag, cast to `bool`. |

Additionally, `SessionStorage::startup()` sets `ini_set('session.cookie_samesite', 'Lax')` if the ini value isn't already set (not gated by any parameter — always applied when SameSite isn't already configured in php.ini).

### `PdoSessionStorage extends SessionStorage`
Purpose: stores session data in a database table via a PDO connection (obtained from `Context::getDatabaseConnection()`), instead of PHP's native session save handler.

| Parameter | Default | Required? | What it does |
|---|---|---|---|
| `db_table` | none | **Required** — `initialize()` throws `InitializationException` if missing. | Table name session rows are read/written/deleted in. |
| `database` | `null` | No | Name of the database connection (as configured in `databases.xml`) to fetch via `Context::getDatabaseConnection($database)`; must resolve to a `\PDO` instance or `open()` throws `DatabaseException`. |
| `db_id_col` | `'sess_id'` | No | Column storing the session ID. |
| `db_data_col` | `'sess_data'` | No | Column storing the session data payload. |
| `db_time_col` | `'sess_time'` | No | Column storing the session's last-write timestamp, used by `gc()` to expire old rows. |
| `data_as_lob` | `true` | No | If true (or the PDO driver is Oracle), session data is bound as `PDO::PARAM_LOB` on write; otherwise `PDO::PARAM_STR`. |
| `date_format` | `'U'` (Unix timestamp) | No | Format string passed to PHP's `date()` to compute the value written to `db_time_col` on `write()`/used to compute the GC cutoff in `gc()`. |

Also inherits all `SessionStorage` parameters (`session_name`, `session_cookie_*`, etc.) since it extends it.

### `NullStorage`
Purpose: a no-op storage — "use a `User` object but no persistent session." Reads/accepts no parameters; `read()`/`retrieve()` always return `false`, `remove()`/`store()`/`write()` are no-ops.

---

## `Quiote\Logging` (sinks — configured programmatically, NOT via settings.xml)

Sinks are wired up in application bootstrap code (e.g. `index.php`), before `Kernel::run()`, via `Log::addSink(new XSink(...))`, plus `Log::setDefaultLevel(Level $level)` and `Log::setLevels(array $categoryPrefix => Level $map)`. There is no `settings.xml`/`factories.xml` entry for any of this — the constructor arguments below ARE the configuration surface.

### `Log` / `LogRegistry` (facade + process-global store)
| Call | Default | What it does |
|---|---|---|
| `Log::setDefaultLevel(Level $level)` | `Level::Info` | Minimum severity accepted process-wide when no category-specific override matches. |
| `Log::setLevel(string $categoryPrefix, Level $level)` | none | Sets/overrides the minimum level for one category prefix (dot-boundary match, e.g. `'Quiote'` matches `Quiote.Routing`). |
| `Log::setLevels(array $map)` | none | Bulk version of `setLevel()`; merges into the existing map. |
| `Log::addSink(SinkInterface $sink)` | none (no sinks by default) | Registers a sink. Multiple sinks may be added; each independently decides via its own `isEnabled()` whether to emit an event. |
| `Log::reset()` | — | Clears default level (back to `Info`), category levels, and all registered sinks — plus `LogContext`. Intended for test isolation/reconfiguration, not the request path. |

Level resolution (both `LogRegistry` for the facade default, and `AbstractStreamSink` per-sink) is "longest matching category-prefix wins, else fall back to the (registry- or sink-level) default" — same algorithm in both places, matching on a dot boundary (`$category === $prefix || str_starts_with($category, $prefix . '.')`).

### `AbstractStreamSink` (base class for all stream-based sinks)
| Constructor param | Default | What it does |
|---|---|---|
| `$minLevel` (`Level`) | `Level::Debug` | Minimum severity this sink accepts, when no category override matches. |
| `$categoryOverrides` (`array<string,Level>`) | `[]` | Per-category-prefix minimum level overrides (longest-prefix-wins). |
| `$stream` (`string`) | `'php://stdout'` | Filesystem/stream path, opened lazily in append (`'a'`) mode on first write. |
| `$streamResource` (`resource\|null`) | `null` | If supplied (an already-open resource), used directly instead of opening `$stream` — mainly for tests. |

### `TextStreamSink extends AbstractStreamSink`
Purpose: human-readable single-line-per-event sink for local dev, e.g. `2026-07-01T08:02:55.123Z WARNING Quiote.Routing: no route matched /foo {rid=abc}`.

| Constructor param | Default |
|---|---|
| `$stream` | `'php://stderr'` (note: overrides the base class's `stdout` default) |
| `$minLevel` | `Level::Debug` |
| `$categoryOverrides` | `[]` |
| `$streamResource` | `null` |

### `FileSink extends AbstractStreamSink`
Purpose: appends the same plain-text line format as `TextStreamSink` to a file on disk — deliberately never colorized (log files are read by more than terminals).

| Constructor param | Default | What it does |
|---|---|---|
| `$path` (`string`) | none (required, 1st positional arg) | File path to append to. Parent directory is auto-created (`mkdir(..., 0775, true)`) if missing and no `$streamResource` was supplied. |
| `$minLevel` | `Level::Debug` | Minimum severity. |
| `$categoryOverrides` | `[]` | Per-category overrides. |
| `$streamResource` | `null` | Pre-opened resource for tests; when supplied, `$path` is never touched and no directory is created. |

### `AnsiTextStreamSink extends TextStreamSink`
Purpose: same plain-text line format, but colors warning-and-above lines for interactive terminals (yellow=warning, red=error, bold red=critical/alert/emergency; debug/info/notice left uncolored).

| Constructor param | Default | What it does |
|---|---|---|
| `$stream` | `'php://stderr'` | Same as `TextStreamSink`. |
| `$minLevel` | `Level::Debug` | Same as base. |
| `$categoryOverrides` | `[]` | Same as base. |
| `$streamResource` | `null` | Same as base. |
| `$colors` (`?bool`) | `null` (auto-detect) | If `null`, colors are auto-enabled unless the `NO_COLOR` env var is set (see no-color.org) or the destination stream is not a TTY (`stream_isatty()`). Pass `true`/`false` to force on/off regardless of environment. |

### `EmojiTextStreamSink extends AnsiTextStreamSink`
Purpose: `AnsiTextStreamSink` output prefixed with a per-level emoji (🔎 trace, 🪲 debug, ℹ️ info, 📝 notice, ⚠️ warning, ‼️ error, 🔥 critical, 🚨 alert, 💀 emergency) for a quick visual scan in dev consoles. No constructor of its own — inherits `AnsiTextStreamSink`'s exact constructor/parameters/defaults.

### `JsonStdoutSink extends AbstractStreamSink`
Purpose: default container/production sink — one compact JSON object per line to `php://stdout` (bare JSON, not via `error_log()`, so it's not double-wrapped by a platform JSON logger like Caddy's). Designed for FrankenPHP/Caddy → AKS → Azure Log Analytics. No constructor of its own — uses `AbstractStreamSink`'s constructor and its defaults (`$minLevel = Level::Debug`, `$categoryOverrides = []`, `$stream = 'php://stdout'`, `$streamResource = null`). Output fields: `ts`, `level`, `category`, `message`, `template` (only if the raw message had `{placeholders}`), `src` (always `"app"`), `exception` (only on exception events; a `chain`+`trace` structure), plus any flattened `scope`/`properties` from the event (reserved field names always win on collision).

### `Level` enum (`Quiote\Logging\Level`)
Not itself a setting, but the value type used throughout: `Trace(50) < Debug(100) < Info(200) < Notice(250) < Warning(300) < Error(400) < Critical(500) < Alert(550) < Emergency(600)`. `Level::fromName(string $name)` parses case-insensitive names for e.g. an env var like `LOG_LEVEL=info` (accepts `warn`/`verbose`/`err`/`crit`/`fatal` as aliases), though nothing in the reviewed scope actually reads such an env var automatically — that parsing would need to be wired up explicitly by application bootstrap code.

---

## `Quiote\Database`

### `Database` (abstract base, `Quiote/Database/Database.php`)
Purpose: base class for a single named database connection, wired via `factories.xml`-style parameters (in practice, `databases.xml`, compiled by `DatabaseConfigHandler`, which is out of this document's scope but produces the same `getParameter()`-consumable shape). Declares no parameters itself; `connect()`/`shutdown()` are abstract. `ping()` default implementation: returns `true` if no connection has been established yet (lazy-connect will handle it), otherwise conservatively returns `false` (forcing a reconnect) unless a subclass overrides it with a real driver-specific probe.

### `DatabaseManager`
Purpose: owns all named `Database` instances for a `Context`; not itself parameterized via `getParameter()` — reads config directly:

| Key | Default | What it does |
|---|---|---|
| `core.config_dir` | (see Quiote.php) | Used to locate and `require`/`include` the compiled `{core.config_dir}/databases.xml` (via `ConfigCache::checkConfig()` or, if `\QUIOTE_USE_APCU_CONFIG_CACHE` is enabled, `APCuConfigCache::checkConfig()`/`eval()`) during `initialize()`. This is what actually populates `$this->databases` and `$this->defaultDatabaseName` (the latter set by the compiled `databases.xml` handler, not read here directly). |

### `PdoDatabase extends Database`
Purpose: PDO-backed database connection.

| Parameter | Default | What it does |
|---|---|---|
| `method` | `'dsn'` | Connection method selector; only `'dsn'` is implemented (any other value silently skips DSN validation and later fails when `$dsn` is used undefined/null). |
| `dsn` | none | **Required** when `method = 'dsn'` — PDO DSN string. Throws `DatabaseException` if missing. |
| `username` | none | PDO username. |
| `password` | none | PDO password. |
| `options` | `[]` | Array of PDO constructor driver options; array keys/values that are strings containing `::` are resolved via `constant()` (e.g. `'PDO::ATTR_PERSISTENT'`). |
| `attributes` | `[]` (merged with a hardcoded default `PDO::ATTR_ERRMODE => PDO::ATTR_ERRMODE_EXCEPTION`, note the default itself cannot be turned off, only added to/overridden) | Array of `$connection->setAttribute()` calls; same `::`-constant resolution as `options`. |
| `init_queries` | `[]` | List of SQL statements executed via `$connection->exec()` immediately after connecting. |
| `warn_mysql_charset` | `true` | If true and the DSN starts with `mysql:`, throws `DatabaseException` if `init_queries` contains a `SET NAMES` statement (charset-escaping security warning; PHP bug 47802), or if the DSN contains `;charset=` on PHP < 5.3.6. Set to `false` to allow `SET NAMES` in `init_queries` (with the caveat documented in the exception message). |

`ping()`: overridden to run `SELECT 1`; on `PDOException`, nulls the connection so the next `getConnection()` reconnects lazily (recovers from e.g. "MySQL server has gone away" after a host sleep).

### `DoctrineDatabase extends Database` (legacy Doctrine 1.x)
| Parameter | Default | What it does |
|---|---|---|
| `dsn` | none | Doctrine connection DSN. |
| `connection_event_listener_class` | `'DoctrineDatabaseEventListener'` | Class name of the Doctrine connection event listener to attach. |
| `date_format` | unset | If present, calls `$connection->setDateFormat(...)`. |
| `options` | `[]` | Iterated and applied as Doctrine connection options. |
| an "attributes" key (name built dynamically; see source around line 111) | `[]` | Applied as Doctrine connection attributes. |
| `impls` | `[]` | Map of template-name → class-name overrides for Doctrine component implementations. |
| `manager_impls` | `[]` | Map of template-name → class-name overrides for the Doctrine manager. |
| `load_models` | unset | Passed to `Doctrine::loadModels(...)`. |
| `models_directory` | unset | Passed to `Doctrine_Core::setModelsDirectory(...)` (Doctrine 1.2+). |
| `extensions_path` | unset | Passed to `Doctrine_Core::setExtensionsPath(...)` (Doctrine 1.2+). |
| `register_extensions` | `[]` | List of extension names to register. |
| `bind_components` | `[]` | List of component names to bind. |

### `Doctrine2Database extends Database` (Doctrine 2.x common base)
| Parameter | Default | What it does |
|---|---|---|
| `class_loaders` | `['Doctrine' => null]` | Map of namespace → include path used to register Doctrine 2 class loaders. |

### `Doctrine2dbalDatabase extends Doctrine2Database`
| Parameter | Default | What it does |
|---|---|---|
| `configuration_class` | `'\Doctrine\DBAL\Configuration'` | Class instantiated as the DBAL configuration object. |
| `event_manager_class` | `'\Doctrine\Common\EventManager'` | Class instantiated as the DBAL event manager. |
| `connection` | `[]` (cast) | Array of DBAL connection parameters passed to `\Doctrine\DBAL\DriverManager::getConnection()`. |

### `Doctrine2ormDatabase extends Doctrine2dbalDatabase`
| Parameter | Default | What it does |
|---|---|---|
| `connection` | none | Name of the configured `Doctrine2dbalDatabase` connection to wrap with the ORM `EntityManager`. |
| `configuration_class` | `'\Doctrine\ORM\Configuration'` | Class instantiated as the ORM configuration object. |
| `event_manager_class` | `'\Doctrine\Common\EventManager'` | Class instantiated as the ORM event manager (only if not reusing the underlying DBAL connection's). |
| `configuration[auto_generate_proxy_classes]` | `Config::get('core.debug')` | Whether Doctrine ORM regenerates proxy classes on every request. |
| `configuration[metadata_driver_impl_argument]` | none | Argument passed to the metadata driver implementation constructor. |
| `configuration[metadata_driver_impl_class]` | none | Metadata driver implementation class; explicitly checked with `hasParameter()` so it can be "deleted" by setting it to `null`. |
| `configuration[proxy_namespace]` | `'Doctrine2ormDatabase_Proxy_' . <sanitized database name>` | PHP namespace generated Doctrine proxy classes live under. |
| `configuration[proxy_dir]` | `Config::get('core.cache_dir')` | Directory generated Doctrine proxy classes are written to. |
| `configuration[metadata_cache_impl_class]` | a default cache implementation | Metadata cache implementation class. |
| `configuration[query_cache_impl_class]` | same default cache implementation | Query cache implementation class. |
| `configuration[result_cache_impl_class]` | same default cache implementation | Result cache implementation class. |

### `PropelDatabase extends Database`
| Parameter | Default | What it does |
|---|---|---|
| `config` | none | Path to the Propel runtime configuration file (expanded via `Toolkit::expandDirectives()`). |
| `datasource` | `null` | Propel datasource name; used both to fetch the connection (`Propel::getConnection($datasource)`) and to look up datasource-specific query settings from the Propel config. |
| `use_as_default` | `false` | Whether this datasource is registered as Propel's default. |
| `overrides` | `[]` (cast) | Array of Propel runtime configuration overrides. |
| `init_queries` | `[]` (cast) | List of SQL statements merged with any queries already defined in the Propel config for this datasource. |
| `enable_instance_pooling` | unset (tri-state: `true`/`false`/unset) | If exactly `true`/`false` (strict comparison), enables/disables Propel instance pooling; any other value (including unset) leaves Propel's own default behavior. |

---

## Summary: settings NOT found / explicitly absent in this scope
- No `core.*` setting reads any environment variable directly inside `Quiote.php`, `Context.php`, the `Storage/` classes, or the `Database/` classes — the only environment variables consumed in the reviewed scope are `QUIOTE_APCU_PREWARM` (in `Quiote::bootstrap()`) and `NO_COLOR` (in `AnsiTextStreamSink`, a PHP-code sink parameter, not a `core.*` setting).
- Logging sinks/levels have **no** `settings.xml`/`core.*` equivalent at all in the reviewed code — they are 100% programmatic, configured in bootstrap code before `Kernel::run()`.
- The correlation-ID header name (for accepting an inbound `X-Correlation-Id`-style header instead of always generating one) is a known gap — see the `Context.php` TODO at the line noted above — not yet a real setting.

---
---

# Configuration Settings Reference — Request-Intake Middleware

Covers the middleware that runs earliest in the pipeline: error handling, session
startup, request timing/tracing, and body/content negotiation — before routing.

### ErrorHandlingMiddleware

`Quiote/Middleware/ErrorHandlingMiddleware.php` — catches unhandled throwables from
downstream middleware/dispatch and renders a safe or detailed (Whoops) error response.

| Key | Default | How to set | What it does |
|---|---|---|---|
| `core.developer_exceptions` | `false` | `settings.xml` under `core.` | Selects the error renderer: `WhoopsRenderer` (detailed/dev error pages) when true, `SafeRenderer` (generic response, no internals leaked) when false. This is the *sole* signal — there is no environment-name sniffing and no separate `QUIOTE_DEBUG` env var. |

Exact source: `Config::get('core.developer_exceptions', false)` in `resolveRenderer()`.

The constructor also takes an optional `?callable $logger` — this is **constructor-argument-only, no config file involved**. In the default pipeline it's always wired to an inline closure that writes to `Quiote\Logging\Log`; there's no config-driven way to swap it short of a full `MiddlewareCatalog::replaceCoreStack()` pipeline replacement.

Unconditionally constructed in `MiddlewarePipeline::doBuild()` (no `MiddlewareCatalog::isEnabled()` guard) — the `<middleware_config>` on/off switch has no effect on this one.

### SessionMiddleware

`Quiote/Middleware/SessionMiddleware.php` — starts/persists PSR-7 session storage and guarantees an `ExecutionState` request attribute exists; skipped entirely for JWT-authenticated requests.

**Configuration: none.** No `Config`/`getParameter` calls at all. Its only input is the injected `Controller $controller` (plain DI, not config-driven). Unconditionally constructed in the default pipeline (no `MiddlewareCatalog::isEnabled()` guard).

Whether *session storage itself* actually persists anything depends on the storage backend selected via `factories.xml` (`SessionStorage`/`PdoSessionStorage`/`NullStorage` — see the Storage section above) — that's configuration of the storage class, not of this middleware.

### TimingMiddleware

`Quiote/Middleware/TimingMiddleware.php` — records total request-processing time into `ExecutionState::$metrics['total_ms']`, optionally emitting an `X-Quiote-Timing` response header.

| Setting | Default | How to set | What it does |
|---|---|---|---|
| Enable/disable via `MiddlewareCatalog` | enabled (unknown FQCN defaults to `true`) | `config_handlers.xml`: `<middleware_config><middleware class="Quiote\Middleware\TimingMiddleware" enabled="false"/></middleware_config>` (see `MiddlewareCatalog` section further below for the full mechanism) | If disabled, the pipeline skips constructing `TimingMiddleware` entirely — no timing metrics/header. |
| `middleware.timing.emit_header` | `false` | `settings.php`/`.xml`/`.yml`/`.yaml` | Passed straight to the `emitHeader` constructor argument; set `true` to emit the `X-Quiote-Timing` response header. |

### TraceMiddleware

`Quiote/Middleware/TraceMiddleware.php` — appends its own class name to `ExecutionState::$metrics['trace']` (a running list of executed middleware), optionally emitting a trace header.

Same enable/disable mechanism as `TimingMiddleware` (`MiddlewareCatalog`/`<middleware_config>`).

| Setting | Default | How to set | What it does |
|---|---|---|---|
| `middleware.trace.emit_header` | `false` | `settings.php`/`.xml`/`.yml`/`.yaml` | Passed to the `emitHeader` constructor argument; set `true` to emit the trace header. |
| `middleware.trace.header_name` | `'X-Quiote-Trace'` | `settings.php`/`.xml`/`.yml`/`.yaml` | Passed to the `headerName` constructor argument; overrides the response header's name. |

### PayloadParsingMiddleware

`Quiote/Middleware/PayloadParsingMiddleware.php` — unified request-body parser (JSON via `middlewares/payload`, plus `application/x-www-form-urlencoded`) run before routing.

| Env var | Default | How to set | What it does |
|---|---|---|---|
| `QUIOTE_JSON_STRICT` (an **OS environment variable**, not a Quiote `Config` key) | strict mode on, unless the env var is literally `'0'` | shell/deploy env: `QUIOTE_JSON_STRICT=0` (any other value, including unset, keeps strict mode on) | `$this->strict = $strict ?? (getenv('QUIOTE_JSON_STRICT') !== '0')`. When strict and the JSON body is invalid, the middleware short-circuits with an HTTP 400 `{"error":"invalid_json",...}` response; when non-strict, invalid JSON is silently ignored and the request proceeds unparsed. |

The constructor also accepts an explicit `?bool $strict = null` that overrides the env var if non-null — but the default pipeline always calls `new PayloadParsingMiddleware()` with no argument, so in practice only the env var matters unless an app replaces the pipeline construction itself. Unconditionally constructed (no `MiddlewareCatalog::isEnabled()` guard).

### JsonBodyParsingMiddleware — removed

Was a legacy/alternate JSON body parser, fully superseded by `PayloadParsingMiddleware`
(which already guards against double-parsing) and never referenced in
`MiddlewarePipeline::doBuild()`. Deleted along with its test and its identity entry in
`rector.php`.

### ContentNegotiationMiddleware

`Quiote/Middleware/ContentNegotiationMiddleware.php` — determines the request's negotiated output format (`output_type`/`output_formats` attributes) from the URL extension or `Accept` header, before routing.

**Configuration: none exposed.** The `private ?string $defaultFormat = 'html'` property is a hardcoded class default — not exposed via constructor, `Config`, or `factories.xml`; changing it requires subclassing/editing the class. The constructor only takes the `Controller $controller` DI dependency. Unconditionally constructed (no `MiddlewareCatalog::isEnabled()` guard).

---
---

# Configuration Settings Reference — Slots, Assets, Forms, and the Middleware Catalog

### SlotMiddleware

`Quiote/Middleware/SlotMiddleware.php` — establishes a `SlotStack` request attribute for nested slot/sub-action rendering. **No configuration.** Constructor takes an optional `?Context $context` directly (not config-driven). Registered via `#[Middleware(phase: 'pre_routing', before: 'RoutingMiddleware')]`.

### AssetAggregationMiddleware

`Quiote/Middleware/AssetAggregationMiddleware.php` — currently a pass-through placeholder (the asset-collection adapter was removed; the body is just `$handler->handle($request)`). **No configuration**, no constructor parameters. Always `new AssetAggregationMiddleware()` in the default pipeline.

### FormPopulationMiddleware

`Quiote/Middleware/FormPopulationMiddleware.php` — runs the form-population engine (auto-fills form values/error messages) against PSR-7 responses.

**No settings read from `Config`/`getParameter` in this file.** Constructor args are direct objects, not config: `Controller $controller` (required) and an optional `?FormPopulationEngine $engine`. Runtime behavior keys off `Quiote\Util\FormPopulationConfig` — a **per-request** state object (seeded via `$engine->getDefaults()`), checking flags like `force_request_uri`/`force_request_url` — but this is per-request application state, not a global `Config`/settings.xml key. Registered via `#[Middleware(phase: 'after_action', after: 'AssetAggregationMiddleware', before: 'ExecutionTimeMiddleware')]`.

### ExecutionTimeMiddleware

`Quiote/Middleware/ExecutionTimeMiddleware.php` — measures request execution time and (optionally) appends an HTML comment `<!-- exec_time=X.XXms -->` to legacy-adapter responses.

| Setting | Default | How to set | What it does |
|---|---|---|---|
| Enable/disable via `MiddlewareCatalog` | enabled | `config_handlers.xml`: `<middleware_config><middleware class="Quiote\Middleware\ExecutionTimeMiddleware" enabled="false"/></middleware_config>` | If disabled, the middleware is never constructed. |

The `$appendHtmlComment` constructor arg (default `true`) is **not** read from `Config` — the default pipeline always instantiates it with no argument (`new ExecutionTimeMiddleware()`), so there is currently no wiring that ever passes `false` in the framework's own pipeline. An app would need `MiddlewareCatalog::register()` with its own factory to change this.

### DiagnosticsMiddleware, LegacyFilterChainMiddleware, FilterMiddlewareAdapter

All three files are empty/placeholder stubs with no class defined (`DiagnosticsMiddleware.php` is a 0-byte file; the other two contain only a `<?php // removed legacy ...` comment). **Nothing to configure** — none of them are referenced in `MiddlewarePipeline::doBuild()`.

### MiddlewareCatalog

`Quiote/Middleware/MiddlewareCatalog.php` — the central static registry for (a) enable/disable flags per middleware FQCN, (b) registering custom middleware with ordering hints, and (c) the full-pipeline-replacement escape hatch (`replaceCoreStack()`, documented separately — see the "Escape hatch" note below).

**Enable/disable map** is populated from a `<middleware_config>` element inside `config_handlers.xml` (compiled by `Quiote\Config\ConfigHandlersConfigHandler`), sitting alongside `<handlers>`:

```xml
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/config_handlers/1.1">
  <ae:configuration>
    <middleware_config>
      <middleware class="Quiote\Middleware\ExecutionTimeMiddleware" enabled="false" />
      <middleware class="Quiote\Middleware\TimingMiddleware" enabled="true" />
    </middleware_config>
    <handlers> ... </handlers>
  </ae:configuration>
</ae:configurations>
```

- `<middleware class="...">` — required attribute, must be the exact FQCN string used at the `MiddlewareCatalog::isEnabled()` call site (e.g. `Quiote\Middleware\ExecutionTimeMiddleware`).
- `enabled="..."` — optional, default `"true"`. Case-insensitively, `"0"`/`"false"`/`"off"`/`"no"` count as disabled; anything else (including omitting the attribute) is enabled.
- Compiles to a reserved `__middleware_config` key in the compiled `config_handlers` array; `Quiote\Config\ConfigCache` detects that key at load time, calls `MiddlewareCatalog::initialize($map)`, and strips the key before merging the rest into the handler config. This is the only production call site of `initialize()` — everywhere else it's unit tests simulating config directly.
- **No live example XML file in this repo currently demonstrates a `<middleware_config>` block** — the schema above is reconstructed directly from the parser code (`ConfigHandlersConfigHandler.php`), since no fixture exists yet.

Programmatic-only API (no XML/config-file equivalent):
- `MiddlewareCatalog::register(string $fqcn, callable $factory, ?string $after = null, ?string $before = null, int $priority = 0)` — insert a custom middleware relative to an existing FQCN label (falls back to "just before `SecurityMiddleware`" if neither hint resolves); `$priority` breaks ties among multiple registrations anchored at the same point.
- `MiddlewareCatalog::replaceCoreStack(callable $factory, string $acknowledgement)` — full pipeline replacement escape hatch. `$acknowledgement` must exactly equal `MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT` or it throws. When active, `MiddlewarePipeline` skips its entire built-in stack (error handling, sessions, CSRF, security, routing, everything) and uses only `$factory($context)`'s output; `register()`-ed middleware is **not** spliced in on top of a replaced stack.

### MiddlewarePipeline

`Quiote/Middleware/MiddlewarePipeline.php` — builds and caches the PSR-15 (`Relay\Relay`-based) middleware chain for a request `Context`.

No settings of its own beyond what it reads through `MiddlewareCatalog`. Default stack, unconditional except where noted:

1. `ErrorHandlingMiddleware` (always)
2. `TelemetryMiddleware` (always constructed; a no-op pass-through unless `Trace::enabled()` — see `docs/OPENTELEMETRY_PLAN.md`, Phase 3)
3. `SessionMiddleware` (always)
4. `TimingMiddleware` — only if `MiddlewareCatalog::isEnabled(TimingMiddleware::class)`
5. `TraceMiddleware` — only if `MiddlewareCatalog::isEnabled(TraceMiddleware::class)`
6. `PayloadParsingMiddleware` (always)
7. `ContentNegotiationMiddleware` (always)
8. `RoutingMiddleware` (always)
9. `OutputTypeSyncMiddleware` (always)
10. `CsrfInjectionMiddleware` (always constructed; actual CSRF behavior gated at runtime by `core.csrf.enabled` — see the CSRF section below)
11. `CsrfValidationMiddleware` (always constructed, same gating)
12. `SecurityMiddleware` (always)
13. `ValidationMiddleware` (always)
14. `SlotMiddleware` (always)
15. `DispatchMiddleware` (always)
16. `AssetAggregationMiddleware` (always)
17. `FormPopulationMiddleware` (always)
18. `ExecutionTimeMiddleware` — only if `MiddlewareCatalog::isEnabled(ExecutionTimeMiddleware::class)`
19. Externally `MiddlewareCatalog::register()`-ed middleware, spliced in per their `after`/`before`/`priority` hints and `isEnabled()` check.
20. A hardcoded terminal sentinel that throws if the pipeline is ever exhausted without producing a response — a PSR-15 contract guarantee, always present (even when the whole stack above is replaced via `replaceCoreStack()`).

Only **TimingMiddleware**, **TraceMiddleware**, and **ExecutionTimeMiddleware** are actually gated by `MiddlewareCatalog::isEnabled()` in the default stack — everything else is unconditional (`TelemetryMiddleware` included: it always runs, but internally no-ops in one `if` check when telemetry is off).

If `MiddlewareCatalog::hasCoreStackOverride()` is true (see `replaceCoreStack()` above), steps 1–19 are skipped entirely in favor of the app-supplied factory's stack, and a `warning`-level log line is emitted noting the replacement.

---
---

# Configuration Settings Reference — Runtime, Request/Response, Cache, Console, I18n/Translation

## Runtime / worker mode (`Quiote\Runtime`)

Boots the app and adapts to FrankenPHP worker mode vs. single-shot CGI/CLI.

**Environment variables (`getenv`, NOT via `Config`):**

| Env var | Default | Read in | What it controls |
|---|---|---|---|
| `QUIOTE_ENV` | `'prod'` (`'development'` in some CLI/sample entry points — inconsistent default across entry points, confirmed as-is) | `Kernel::create()`, `AbstractAppCommand.php`, sample `pub/index.php` | Environment name passed to `Quiote::bootstrap()`; becomes `core.environment`. |
| `QUIOTE_CONTEXT` | `'web'` | `Kernel::create()` | Primary context name to create/prime. |
| `QUIOTE_MAX_REQUESTS` | `1000` | `Kernel.php`, passed to `WorkerManager::configure(['max_requests_before_cleanup' => ...])` | Number of FrankenPHP worker requests handled before `WorkerManager::performDeepCleanup()` runs. |
| `QUIOTE_APCU_PREWARM` | unset (falsy) | `Quiote.php` (checked against `1`/`true`/`yes`/`on`, case-insensitive) | Forces `Quiote::prewarm()` even if `core.apcu_prewarm` isn't set, when the APCu config cache is active. |
| `QUIOTE_APP_DIR` | none | `AbstractAppCommand.php` | CLI app-dir resolution fallback when `--app-dir` isn't passed. |

Worker-mode selection itself is **not** config-driven: `Kernel::selectWorkerAdapter()` picks `FrankenPhpWorkerAdapter` purely via `function_exists('frankenphp_handle_request')`; otherwise falls back to `SingleRequestAdapter`. No setting toggles this.

**Config-based settings** (in addition to the `core.*` bootstrap keys already documented above): `core.app_dir` (required), `core.default_context` (falls back to `QUIOTE_CONTEXT`/`'web'`), and the standard derived directory keys — see the `Quiote.php` section above. `Kernel::create(array $options)` also accepts `env`/`context`/`psr`/`app_dir`/`autoload_paths`/`prewarm`/`contexts` directly as **constructor/options-array arguments**, not read from any config file.

### Isolated test-bootstrap env vars (`Quiote/Testing/scripts/IsolatedBootstrap.php`)

The actual read site for the vars referenced in `phpunit.xml`. Environment variables, not `Config`, with a cross-process temp-file fallback for PHPUnit process isolation.

| Env var | Default | What it does |
|---|---|---|
| `QUIOTE_ISOLATION_ID` | none | When set, settings are read from a JSON file at `sys_get_temp_dir()/quiote_isolation_<id>` instead of env vars directly. |
| `QUIOTE_ISOLATION_ENVIRONMENT` | `null` | Environment name passed to `Quiote::bootstrap($environment)` for the isolated test process. |
| `QUIOTE_ISOLATION_DEFAULT_CONTEXT` | `null` | Sets `Config::set('core.default_context', ...)` for the isolated process. |
| `QUIOTE_ISOLATION_CLEAR_CACHE` | falsy | If truthy, deletes everything under `Config::get('core.cache_dir')` and clears the toolkit cache before/after bootstrap. |
| `QUIOTE_ISOLATION_NO_BOOTSTRAP` | falsy | If set, skips calling `Quiote::bootstrap()` entirely in the isolated process. |

Falls back to a parent-PID-keyed temp file if no `QUIOTE_ISOLATION_ID` file is found.

## `Quiote\Request\WebRequest`

Confirmed by full read: only one `Config`-based setting exists in this class (no separate trusted-proxy, upload-limit, or forwarded-header settings — forwarded-header parsing in `Kernel::preAdjustServerGlobalsForProxy()` is unconditional code, not gated by any setting).

| Key | Default | How to set | What it does |
|---|---|---|---|
| `core.trusted_hosts` | `[]` (no restriction) | `settings.xml`: `<settings><setting name="trusted_hosts"><ae:parameter>example.com</ae:parameter><ae:parameter>/^.*\.example\.com$/</ae:parameter></setting></settings>` (nested `<ae:parameter>` children compile to an array) → `core.trusted_hosts` | Host-header-poisoning mitigation, applied in the legacy `bootstrapFromServerParams()` path. Entries longer than 1 char starting and ending with `/` are treated as regexes (`preg_match`); everything else is matched case-insensitively (`strcasecmp`). If the incoming `Host` header matches nothing, it's silently replaced with the first literal (non-regex) entry. Empty/unset = no restriction (backward compatible default). |

## `Quiote\Response`

No single "default headers / output buffering / compression" settings module — a mix of two `Config::get` calls plus per-response `factories.xml` parameters.

**Config-based** (`WebResponse::sendHttpResponseHeaders()`):

| Key | Default | What it does |
|---|---|---|
| `core.expose_quiote_version` | `ini_get('expose_php')` | If truthy, `X-Powered-By` is built from `quiote.release`; otherwise from `quiote.name`. |
| `quiote.release` / `quiote.name` | set in `Quiote/version.php` | Used as the `X-Powered-By` value depending on the above. |

**`factories.xml` `<ae:parameter>`-based** (via `WebResponse::initialize($context, $parameters)`):

| Parameter | Default | What it does |
|---|---|---|
| `cookie_lifetime` | `0` | Default cookie lifetime (seconds, or a `strtotime()` string). |
| `cookie_path` | `null` → routing base path or `/` | Default cookie path. |
| `cookie_domain` | `""` | Default cookie domain. |
| `cookie_secure` | auto-detected from request scheme unless passed explicitly | Secure-cookie flag. |
| `cookie_httponly` | `true` | HttpOnly flag. |
| `cookie_encode_callback` | `'urlencode'` | Callback (or `false`) to encode cookie values before sending. |
| `cookie_samesite` | `'Lax'` | SameSite attribute. |
| `send_content_length` | `true` | Whether to auto-set `Content-Length`. |
| `send_redirect_content` | `false` | Whether to send a body alongside a redirect. |
| `expose_quiote` | `true` | Whether to send `X-Powered-By` at all. |
| `use_sendfile_header` | `false` | Use an `X-Sendfile`-style header instead of streaming file content. |
| `sendfile_header_name` | `'X-Sendfile'` | Header name used for the above. |
| `append_eol` (`ConsoleResponse` only) | `true` | Append `PHP_EOL` after console output. |

No output-buffering or compression settings exist anywhere in `Quiote/Response/`.

## `Quiote\Cache`

| Key | Default | How to set | What it does |
|---|---|---|---|
| `core.cache_backend` | none (falls through to filesystem) | `settings.xml` under `core.`; only the literal value `'apcu'` is recognized | Selects the PSR-16 cache backend. `'apcu'` only takes effect if `apcu_enabled()` is also true at runtime; otherwise silently falls back to filesystem. |
| `core.cache_dir` | `{core.app_dir}/cache` | (see `Quiote.php` bootstrap section) | Base directory for the filesystem cache backend (`<cache_dir>/psr-cache`); falls back to `sys_get_temp_dir()/quiote_cache` if empty. |

`ActionViewCache`'s TTL (`?int $defaultTtlSeconds = 300`) and `FileCache`'s directory are **constructor-argument-only** — whoever wires these instances (typically `factories.xml`) can override via `<ae:parameter>`, but there's no dedicated `core.*` key for either. The APCu *config-cache* prewarm keys (`core.apcu_prewarm`, `QUIOTE_APCU_PREWARM`, documented under Runtime/`Quiote.php`) are a **separate mechanism** from `core.cache_backend` — one caches compiled config, the other is the general-purpose PSR-16 cache.

## `Quiote\Console`

Purely code/CLI-option driven — reads already-bootstrapped `Config` values rather than defining new ones.

| Key | Default | Read in |
|---|---|---|
| `quiote.version` | `'dev'` | `AboutCommand`, `Application` |
| `core.app_dir` | none | `AboutCommand`, `AbstractAppCommand` |
| `core.environment` | none | `AboutCommand` |
| `core.module_dir` | none | `AboutCommand` |
| `core.namespace_prefix` | `'App'` | `AboutCommand`, `AbstractAppCommand` (builds the app-namespace fallback autoloader mapping) |
| `core.default_context` | `'web'` | `RoutesListCommand` (falls back to a `--context` CLI option first) |

Precedence for app-dir/env resolution: explicit CLI option (`--app-dir`/`--env`) > env var (`QUIOTE_APP_DIR`/`QUIOTE_ENV`) > upward filesystem search for `Config/settings.*` (app-dir) or the literal default `'development'` (env).

## `Quiote\Http`, `Quiote\I18n`

**No configuration settings found in either directory.** `CookieSerializer::bridge()` takes `$basePath` as a plain method argument (default `'/'`); `PsrResponseAdapter`/`PsrServerRequestAdapter`/`MimeTypeRegistry`/`ProblemDetails`/`SimpleStream`/`SimpleUri` have no `Config` involvement at all. `DateTimeFacade` (the only file under `Quiote/I18n/`) takes locale/timezone/pattern as explicit method arguments only — zero `Config`/env involvement.

## `Quiote\Translation`

Configured via a **separate config file, `translation.xml`** (compiled by `Quiote\Config\TranslationConfigHandler`), not `settings.xml`/`core.*` keys.

| `translation.xml` element/attribute | Default | What it does |
|---|---|---|
| `<available_locales default_locale="...">` | none — throws if still unset after load | Default locale identifier. |
| `<available_locales default_timezone="...">` | `null` → falls back to `date_default_timezone_get()` | Default timezone. |
| `<translators default_domain="...">` | `''` | Default translation domain used by `_()`/`_n()`/`_c()`/`_d()` when no domain is given. |
| per-locale `<ae:parameter>` children | `[]` | Locale-specific parameters passed to `QuioteLocale::initialize()`. |
| per-domain `<message_translator class="...">` etc. | msg→none, num→`QuioteNumberFormatter`, cur→`CurrencyFormatter`, date→`DateFormatter` | Translator/formatter class + constructor params per domain/type. |

**`factories.xml` `<ae:parameter>`-based** (on `GettextTranslator`):

| Parameter | Default | What it does |
|---|---|---|
| `text_domains` | `[]` | Map of domain → `.mo` file base path. |
| `text_domain_pattern` | `null` | Path pattern with `{domain}`/`{locale}` placeholders, used when no explicit `text_domains` entry exists. |
| `store_calls` | disabled | Directory to write `xgettext`-parseable dumps of every translation call (dev/i18n-extraction mode). |

`SimpleTranslator` takes its entire translation table as its `$parameters` array (domain → locale → key → value) — fully data-driven via `factories.xml`, no separate keys. No `core.*` keys and no environment variables are used anywhere in `Quiote/I18n/` or `Quiote/Translation/`.

---
---

# Configuration Settings Reference — CSRF, Security, Validation, Routing, User, DI

## CSRF protection (`Quiote\Security\Csrf`)

Source of truth: `packages/csrf/src/CsrfManager.php`.

| Key | Default | What it controls |
|---|---|---|
| `core.csrf.enabled` | `true` | Master on/off switch for CSRF injection and validation. |
| `core.csrf.token_id` | `'quiote_csrf'` | Symfony `CsrfTokenManager` token-id namespace. |
| `core.csrf.field_name` | `'_csrf_token'` | Hidden form field name injected into non-GET `<form>` elements and read back on submission. |
| `core.csrf.header_name` | `'X-CSRF-Token'` | HTTP header XHR/fetch/API clients must send the token in. |
| `core.csrf.cookie_name` | `'XSRF-TOKEN'` | Readable (non-HttpOnly) cookie name delivering the token to same-origin SPA/JS clients. |
| `core.csrf.safe_methods` | `['GET', 'HEAD', 'OPTIONS', 'TRACE']` | HTTP methods that skip CSRF validation entirely (compared case-insensitively, upper-cased). |

Token generation always uses Symfony's default `UriSafeTokenGenerator` (random-bytes-based) — hardcoded, not configurable.

**Where `core.csrf.enabled` is actually checked** (vs. just mentioned in a comment):
- `CsrfManager::isEnabled()` (`packages/csrf/src/CsrfManager.php`) — the single read site: `return (bool) Config::get('core.csrf.enabled', true);`
- `CsrfInjectionMiddleware::process()` and `CsrfValidationMiddleware::process()` both call `$csrf->isEnabled()` and short-circuit (skip injection / skip validation and pass the request through) if false.
- `MiddlewarePipeline.php`'s comment above where both CSRF middleware are constructed ("Behavior is gated at runtime by core.csrf.enabled") is **documentation only** — the pipeline itself doesn't branch on the flag; both middleware are always constructed and gate themselves internally.

Example `settings.xml` (array-valued setting via nested `<ae:parameter>`, environment-scoped override to fully disable CSRF under a testing environment):
```xml
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/settings/1.1">
  <ae:configuration>
    <settings>
      <setting name="csrf.enabled">true</setting>
      <setting name="csrf.token_id">quiote_csrf</setting>
      <setting name="csrf.field_name">_csrf_token</setting>
      <setting name="csrf.header_name">X-CSRF-Token</setting>
      <setting name="csrf.cookie_name">XSRF-TOKEN</setting>
      <setting name="csrf.safe_methods">
        <ae:parameter>GET</ae:parameter>
        <ae:parameter>HEAD</ae:parameter>
        <ae:parameter>OPTIONS</ae:parameter>
        <ae:parameter>TRACE</ae:parameter>
      </setting>
    </settings>
  </ae:configuration>
  <ae:configuration environment="testing.*">
    <settings>
      <setting name="csrf.enabled">false</setting>
    </settings>
  </ae:configuration>
</ae:configurations>
```
(A bare `<settings>` block lands these under `core.` by default — which is exactly why `<setting name="csrf.enabled">` compiles to `core.csrf.enabled`, no `prefix` attribute needed since `csrf.enabled` is itself the sub-key.)

### `SessionTokenStorage` (`packages/csrf/src/SessionTokenStorage.php`)
Symfony `TokenStorageInterface` implementation persisting CSRF tokens through `Context::getStorage()` instead of PHP native sessions. **No configuration** — the only "setting" is a hardcoded class constant `PREFIX = 'org.quiote.csrf.'` used to namespace storage keys.

### `LoginThrottle` (`packages/ratelimit/src/LoginThrottle.php`)
Sliding-window brute-force/login throttle on top of `symfony/rate-limiter`. **Constructor-argument-only — no config file involved:**
```php
public function __construct(
    StorageInterface $storage,
    int $maxAttempts = 10,
    string $interval = '15 minutes',
    string $id = 'quiote_login'
)
```
Policy is hardcoded to `'sliding_window'`. **Not currently wired into any login flow** — the only consumer in the repo is its own unit test; it's a ready-to-use primitive awaiting integration, not dead code.

### `PdoRateLimiterStorage` (`packages/ratelimit/src/PdoRateLimiterStorage.php`)
PDO-backed rate-limiter storage. **Constructor-argument-only:**
```php
public function __construct(private \PDO $pdo, private string $table = 'quiote_rate_limit')
```

## `SecurityMiddleware` / `ValidationMiddleware` — non-CSRF settings

| Key | Default | Where | What it does |
|---|---|---|---|
| `core.use_security` | `true` | `Quiote/Middleware/SecurityMiddleware.php` | If `false`, `SecurityDecision::Allow` is forced unconditionally — `SecurityService::decide()` is never consulted, i.e. security checks are fully bypassed. |
| `core.expose_validation_errors_header` | `false` | `Quiote/Middleware/ValidationMiddleware.php` | If `true`, a base64-encoded JSON blob of validator error messages is attached as the `X-Quiote-Validation-Errors` response header on a 400. Off by default deliberately (leaks internal field/validator structure to the client) — intended only for a trusted dev/test front end. |

Both are plain `Config::get('core.xxx', default)` calls under the standard `core.` prefix.

## Validator compiler (`Quiote\Validator\Compiler\ValidatorPlanBuilder`)

`validators.xml` is compiled by walking `<validator>` elements; XML attributes plus nested `<ae:parameter name="x">value</ae:parameter>` children seed each validator's parameter bag. Compile-time (not per-request) enforcement:

| Key | Default | Values | Effect |
|---|---|---|---|
| `validation.reject_unknown_parameters` | `'throw'` | `'throw'`, `'warn'`, `'off'` | Governs how the compiler reacts to a validator parameter name not declared in that validator class's `getAcceptedParameters()`. |

- `'throw'` (default): throws `Quiote\Exception\ConfigurationException` immediately, aborting the compile — message includes the validator name/class/source file, the unknown parameter, a Levenshtein-based "did you mean" hint (edit distance ≤ 3), and the full accepted-parameter list.
- `'warn'`: logs a `warning`, records a `Diagnostic` (`CODE_UNKNOWN_PARAMETER`) retrievable via `ValidatorPlanBuilder::getDiagnostics()`, and continues — useful for auditing an existing corpus before flipping to `'throw'`.
- `'off'`: skips the check entirely.
- If the validator class can't be introspected yet (not autoloadable / not a `Validator` subclass), the check is skipped regardless of mode, but a `notice` + `Diagnostic` (`CODE_UNRESOLVABLE_CLASS`) is still recorded rather than silently pretending the check ran.

Needs the `validation.` prefix (not the default `core.`):
```xml
<settings prefix="validation.">
  <setting name="reject_unknown_parameters">warn</setting>
</settings>
```
or programmatically: `Config::set('validation.reject_unknown_parameters', 'warn');`

### `Validator` base class — `getAcceptedParameters()`
Not itself a `Config` setting — a static allowlist method every `Validator` subclass should override (merging onto, not replacing, the parent set), checked by `ValidatorPlanBuilder::checkParameters()` above:
```php
public static function getAcceptedParameters(): array
{
    return [
        'name', 'class', 'method',           // structural XML attributes
        'base', 'source',                     // input source / path
        'depends', 'provides',                // dependency graph
        'severity', 'required',               // outcome / severity
        'export', 'export_severity', 'export_to_source', // export
        'translation_domain',                 // i18n
    ];
}
```
This mechanism exists specifically to close a real bug class: a `values="a,b,c"` allowlist attribute on `SecureStringValidator` was once silently accepted into the parameter bag and never enforced.

### Built-in validators (`Quiote/Validator/*.php`)
`AndoperatorValidator`, `ArraylengthValidator`, `BaseFileValidator`, `BooleanValidator`, `DateTimeValidator`, `EmailValidator`, `EqualsValidator`, `FileValidator`, `ImageFileValidator`, `InarrayValidator`, `IsNotEmptyValidator`, `IssetValidator`, `JsonValidator`, `NotoperatorValidator`, `NumberValidator`, `OperatorValidator`, `OroperatorValidator`, `RegexValidator`, `SetValidator`, `StringValidator`, `XoroperatorValidator`. Each declares its own accepted-parameter set the same way; documenting all of them individually is out of scope here (that's what `getAcceptedParameters()` + the compile-time check are for) — one fully worked example:

**`NumberValidator`** accepts (beyond the base set above): `no_locale`, `in_locale`, `type`, `cast_to`, `min`, `max`.
```xml
<validator class="NumberValidator" name="age_check">
    <arguments>
        <argument>age</argument>
    </arguments>
    <ae:parameter name="type">integer</ae:parameter>
    <ae:parameter name="cast_to">integer</ae:parameter>
    <ae:parameter name="min">0</ae:parameter>
    <ae:parameter name="max">150</ae:parameter>
    <ae:parameter name="no_locale">true</ae:parameter>
</validator>
```
`type` (`int`/`integer`/`float`/`double`/anything else) selects the numeric-format check; `min`/`max` bound the parsed value; `cast_to` controls the final PHP type (falls back to `type`); locale-aware parsing (via `DecimalFormatter`) is used only when `core.use_translation` is on and `no_locale` isn't set.

## Routing (`Quiote\Routing`)

`Quiote\Routing\Routing` (abstract base, wraps Symfony's `UrlMatcher`/`RouteCollection`/`RequestContext`) has **no `Config` involvement at all** — base path, trailing-slash handling, host matching, etc. are all delegated to Symfony's own `RequestContext` defaults, either injected or constructed bare. `getBasePath()` is hardcoded to always return `'/'`, not configurable even via constructor.

| Key | Default | Where | What it does |
|---|---|---|---|
| `core.module_dir` | none | `Quiote\Routing\Compiler\AttributeRouteScanner::scan()` | Directory scanned for `{Module}/Actions/**/*Action.php` files when building routes from `#[Route]` PHP attributes, if the caller doesn't pass explicit `$moduleDirs`. |
| `core.namespace_prefix` | `'App'` | same class | Namespace prefix used to derive action FQCNs for attribute discovery. |

Both are bypassable per-call: `AttributeRouting::moduleDirs()` (protected, returns `null` by default) and `AttributeRoutes::mergeInto(..., ?iterable $moduleDirs = null)` can be given explicit directories, skipping `Config` entirely — **constructor/method-argument configuration**.

`CompatRouter` is a thin `Routing` subclass with no configuration of its own. `HttpRedirectRoutingCallback` is a `factories.xml`-style routing callback configured entirely via `<ae:parameter>` children (`route`, `arguments`, `options`, `url`, `scheme`/`host`/`port`/`user`/`pass`/`path`/`query`/`fragment`, `code` — default `302`) — a route-definition concern, not a global setting.

`core.trusted_hosts` (Host-header validation) already documented above under `Quiote\Request\WebRequest`.

## User (`Quiote\User`)

Class hierarchy: `User` → `SecurityUser` (implements `ISecurityUser`) → `RbacSecurityUser`. **There is no authentication-timeout setting anywhere in this layer** — `$authenticated` has no TTL/expiry logic; it's a persistent boolean until explicit `setAuthenticated(false)`. Session-level expiry (if any) lives in the `Storage` backend (session cookie lifetime, GC), not here.

| Setting | Mechanism | Default | How to set |
|---|---|---|---|
| User implementation class | `factories.xml` `<user class="...">` | none — required (`Context::initialize()` throws if unset) | `<user class="Quiote\User\RbacSecurityUser" />` in `factories.xml` |
| RBAC definitions file | factory param `definitions_file` on `<user>` | `Config::get('core.config_dir') . '/rbac_definitions.xml'` | `<user><ae:parameter name="definitions_file">/custom/path.xml</ae:parameter></user>` |
| Storage namespace for user attributes | factory param `storage_namespace` on `<user>` | `'org.quiote.user.User'` | `<user><ae:parameter name="storage_namespace">custom.ns</ae:parameter></user>` |
| Auth/credential/roles storage keys | hardcoded `const` (`AUTH_NAMESPACE`, `CREDENTIAL_NAMESPACE`, `ROLES_NAMESPACE`) | fixed strings | **not configurable** |

The RBAC definitions file itself (`rbac_definitions.xml`) is compiled/cached via `Quiote/Config/RbacDefinitionConfigHandler.php`, the same `ConfigCache` pattern as every other Quiote config file.

## DI Container (`Quiote\DI\Container`)

**No configuration surface at all — confirmed by grepping the entire `Quiote/DI/` directory for `Config::get`/`Config\Config`: zero matches.** It is wired entirely in PHP:
- Services are registered programmatically (`Container::set()`, `alias()`, `setFactory()`), primarily from `Context::registerCoreServicesInContainer()`.
- Autowiring (constructor injection, `#[Inject]`, `#[Autowire]`, `#[Required]`, `#[Service(scope: ...)]`) is reflection/attribute-driven.
- Default scope resolution is a pure code rule (`#[Service(scope:...)]` wins; `ServiceInterface`-implementing classes default to `transient`; otherwise `singleton`) — no config override point exists.

If you need to change DI wiring, you do it in PHP (attributes on the service class, or explicit `Container::set()`/`setFactory()` calls) — there is no settings.xml/factories.xml equivalent for the container itself.

---
---

# Configuration Settings Reference — Telemetry

Full design: `docs/OPENTELEMETRY_PLAN.md`. Phases 1 and 2 are implemented (real
SDK-backed tracer/meter providers, gated on `telemetry.enabled`); Phases 3–9
(root request span + resource metrics, sampling, category filtering, span
tree, propagation, per-middleware spans) are not. This table covers only what
exists today and will grow as later phases land.

### `Quiote\Telemetry\Trace` / `TraceRegistry`

Mirrors `Log`/`LogRegistry`'s split: `Trace` is the static facade,
`TraceRegistry` is the process-global store — except `TraceRegistry` also holds
the worker-lifetime `TracerProvider`/`MeterProvider` singletons once
`TelemetryBootstrap` has built them. Until that has run (or if it declined —
see below) every acquisition call resolves to a shared no-op handle
(`NoopSpanHandle`/`NoopMeterHandle`), so instrumenting a call site is always
safe: it costs nothing when disabled, and needs no changes once telemetry is
turned on.

| Call | What it does |
|---|---|
| `Trace::setEnabled(bool $enabled)` | Sets the master enabled flag directly. Normally set automatically by `TelemetryBootstrap::configureFromConfig()`, not called by hand except in tests. |
| `Trace::enabled(): bool` | True only when telemetry is on **and** actually operative (a real provider is wired up) — see `TelemetryBootstrap` below for what "operative" requires. |
| `Trace::reset()` | Clears the flag and drops the provider references. For test isolation/reconfiguration (simulating a fresh worker), not the request path. |
| `Trace::span(string $category, string $name, array $attributes = []): SpanHandle` | Returns a real `OtelSpanHandle` (wrapping an activated OpenTelemetry span) when enabled, else the shared `NoopSpanHandle`. `$category` is a dot-namespaced trace category (mirrors log categories, e.g. `"Quiote.Routing"`) recorded as the `quiote.trace.category` span attribute; the category-based *filtering* described in Phase 5 of the plan is not yet implemented, so it doesn't gate anything yet. |
| `Trace::current(): SpanHandle` | The currently active span (via OTel's `Span::getCurrent()`), or the no-op handle if none is open or telemetry is off. Returns a **borrowed** reference (`OtelSpanHandle`'s `ownsLifecycle: false`) — letting it go out of scope never ends the real span; only an explicit `->end()` call does. This was a real bug (fixed after the OTel Collector end-to-end verification found it — see `docs/OPENTELEMETRY_E2E_VERIFICATION.md`): `RoutingMiddleware` capturing `Trace::current()` into a local variable to rename the root span was silently ending it via `__destruct()` when that variable went out of scope mid-exception-unwind, discarding the root span's error status before `TelemetryMiddleware` ever got to set it. |
| `Trace::metrics(): MeterHandle` | A real `OtelMeterHandle` (caches Counter/Histogram/Gauge instruments by name for the worker's lifetime) when enabled, else the shared `NoopMeterHandle`. |

### `Quiote\Telemetry\TelemetryBootstrap` (Phase 2)

Builds the real provider from `telemetry.*` config, called once per worker from
`Kernel::bootstrap()` (`Quiote/Runtime/Kernel.php`) — callers never need their
own feature-flag check. Every failure mode degrades to "telemetry stays off"
rather than throwing: telemetry disabled, `open-telemetry/sdk` not installed,
bad exporter config, a missing PSR-18 client for the OTLP exporter, or any
other SDK construction error. An unrecognized `telemetry.exporter` value is the
one exception — that falls back to the local in-memory exporter (with a
warning) rather than disabling telemetry entirely over a typo.

| Call | What it does |
|---|---|
| `configureFromConfig(): bool` | Idempotent per process. Returns whether a real, usable provider is now active. |
| `flushAfterRequest(): void` | Force-flushes both providers. Wired into `Kernel`'s post-request reset closure — runs once per request in worker mode. Safe no-op if telemetry was never configured. |
| `shutdown(): void` | Final flush + provider shutdown; registered once via `register_shutdown_function` so single-shot mode (no persistent loop, no per-request reset closure) still exports its one request before the process exits. |
| `reset(): void` | Test-only: tears down bootstrap + registry state so `configureFromConfig()` can rebuild cleanly, simulating a fresh worker. |
| `inMemorySpanExporter()` / `inMemoryMetricExporter()` | Non-null only when `telemetry.exporter = none`; lets tests inspect exported spans/metrics without reaching into SDK internals. |

**`telemetry.*` settings (`Config`)**

| Key | Default | What it does |
|---|---|---|
| `telemetry.enabled` | `false` | Master gate. Declared in `samples/app/Config/settings.php`. |
| `telemetry.exporter` | `'otlp'` (code default) | `none` (in-memory, for tests/local inspection), `console` (human-readable stdout via the SDK's built-in stream transport — no extra client needed), or `otlp` (needs a PSR-18 client installed; see `docs/OPENTELEMETRY_PLAN.md`'s Dependencies section for why one isn't bundled even in `require-dev`). Unrecognized values fall back to `none` with a logged warning. |
| `telemetry.export.mode` | `'batch'` under FrankenPHP worker mode, `'simple'` otherwise (code default, both overridable) | `simple` uses `SimpleSpanProcessor` (synchronous export on span end); `batch` uses `BatchSpanProcessorBuilder`. |
| `telemetry.service.name` | falls back to `core.app_name`, then `'quiote-app'` | `service.name` resource attribute. |
| `telemetry.service.namespace` | unset | `service.namespace` resource attribute, when set. |
| `telemetry.resource` | `[]` | Extra resource attributes merged in as-is (key => value). |
| `telemetry.otlp.endpoint` | `'http://localhost:4318'` | Bridged to `OTEL_EXPORTER_OTLP_ENDPOINT` — only applied when `telemetry.exporter = otlp`. |
| `telemetry.otlp.protocol` | `'http/protobuf'` | Bridged to `OTEL_EXPORTER_OTLP_PROTOCOL`. |
| `telemetry.otlp.headers` | `[]` | Bridged to `OTEL_EXPORTER_OTLP_HEADERS` as a comma-joined `key=value` list, when non-empty. |
| `telemetry.sampling.strategy` | `'parentbased_traceidratio'` | `always_on`, `always_off`, or `parentbased_traceidratio` (ratio applies to locally-initiated root spans only; a sampled/unsampled parent's decision is always inherited — see `ForceSampleSampler`/`ParentBased` below). Unrecognized values fall back to `parentbased_traceidratio` with a logged warning, same pattern as `telemetry.exporter`. |
| `telemetry.sampling.ratio` | `0.1` | Fraction of locally-initiated root traces recorded when `telemetry.sampling.strategy = parentbased_traceidratio` (or when a bad strategy value fell back to it). **Behavior-changing note**: Phases 2–3 hardcoded `AlwaysOnSampler` (100%); this default is the reason `telemetry.enabled = true` alone now yields ~10% trace capture unless `telemetry.sampling.*` is also set — see `docs/OPENTELEMETRY_PLAN.md`'s Phase 4 status notes. |
| `telemetry.sampling.force_header` | `'X-Quiote-Trace'` | Header name `TelemetryMiddleware` checks for a truthy value (`1`/`true`/`yes`, case-insensitive) to force-sample one request regardless of the ratio. Set to `''` to disable the header path entirely (the `quiote.force_sample` PSR-7 request attribute path — see below — still works). |

### `Quiote\Telemetry\ForceSampleSampler` (Phase 4)

Wraps whichever sampler `telemetry.sampling.*` builds. If the span's
creation-time attributes include `quiote.force_sample === true` (strict — a
truthy-but-not-boolean value like `'1'` does **not** count), the decision is
`RECORD_AND_SAMPLE` unconditionally, bypassing the ratio/strategy entirely for
that one span. Otherwise it defers entirely to the wrapped sampler.

`TelemetryMiddleware` is what actually sets `quiote.force_sample` — from
either the configured header above, or a `quiote.force_sample` PSR-7 request
attribute an app or earlier middleware can set programmatically (checked
first, independent of the header setting). Because sampler attributes are
only visible at span-creation time (a later `setAttribute()` call is invisible
to the sampler — an OTel API contract, not a Quiote-specific limitation), this
has to be threaded in when `Trace::span()` is called, not decided some other
way afterward.

A span created while a force-sampled span is the active parent inherits
sampling via the wrapped `ParentBased` sampler's local-parent-sampled branch
(defaults to `AlwaysOnSampler`) — so force-sampling a root span also captures
everything nested under it in the same request, without each nested span
needing its own force-sample attribute.

### `Quiote\Telemetry\Trace` category filtering (Phase 5)

A second, orthogonal filter axis from sampling above: a deterministic,
per-category on/off switch for silencing a noisy or uninteresting subtree
regardless of the sampling decision. **Configured in code (index.php,
alongside `Log::setLevels(...)`), NOT via `settings.xml`** — the category map
can't naturally be a flat settings entry, and keeping the whole subsystem
code-configured avoids an ordering footgun with `Kernel::bootstrap()` (see
`docs/OPENTELEMETRY_PLAN.md`'s Phase 5 status notes for the full reasoning).

| Call | Default | What it does |
|---|---|---|
| `Trace::setCategoryEnabled(string $categoryPrefix, bool $enabled)` | — | Enable/disable one category prefix. |
| `Trace::setCategories(array $map)` | — | Bulk version; merges into the existing map. |
| `Trace::setDefaultCategoryEnabled(bool $enabled)` | `true` | Fallback for a category matching nothing on its prefix chain. |

**Deliberately NOT `LogRegistry::resolveLevel()`'s longest-prefix-wins
semantics.** Logging lets a more specific child override its parent; category
filtering makes a disabled ancestor (or the category itself) win
*unconditionally* — no explicit `true` on a descendant can re-enable it. This
is what makes disabling a category a real kill switch: `'Quiote.Validation' =>
false` silences every span under it, including one explicitly marked
`'Quiote.Validation.Rules' => true`, without having to enumerate every leaf.
Only once nothing on the chain is disabled does longest-prefix matching among
explicit `true` entries apply (same mechanics as logging), falling back to
`setDefaultCategoryEnabled()`'s value.

A filtered-out category makes `Trace::span()` return the same shared
`NoopSpanHandle` used when telemetry is globally off — no OTel context is
touched, so a differently-categorized (enabled) span opened by code running
"underneath" a filtered call still correctly parents onto the nearest
recorded ancestor, not onto nothing. Applies to spans only — `Trace::metrics()`
takes no category argument, so metrics are never affected by category state.

Unlike logging (sinks/levels are 100% programmatic, no `settings.xml`
equivalent), most of telemetry is primarily settings-driven — see the plan's
rationale for why sampling/exporter/span-depth config lends itself better to
declarative settings than the logging sink model. Category filtering is the
one telemetry piece that follows logging's code-configured model instead, for
the reasons above. The provider singletons themselves are also *not* rebuilt
from settings per request — see `TraceRegistry` above.

### `Quiote\Middleware\TelemetryMiddleware` (Phase 3)

Opens the root request span and records the headline resource measurements.
**No settings of its own** — entirely gated by `Trace::enabled()` (itself
driven by `telemetry.enabled` via `TelemetryBootstrap`). Registered
unconditionally in `MiddlewarePipeline::doBuild()`'s default stack
(`#[Middleware(phase: 'bootstrap', priority: 950)]`, just inside
`ErrorHandlingMiddleware`) — see the "Default pipeline order" list further up
this document. A single `if (!Trace::enabled())` pass-through when telemetry
is off, so it's always safe to leave in the pipeline.

Root span: opened with name `"{METHOD} {PATH}"` (e.g. `"GET /orders/42"`),
kind `Server`. **Renamed by `RoutingMiddleware` once a route matches** (Phase
6, below) to the low-cardinality route identity (e.g. `"GET /orders/{id}"`)
— `TelemetryMiddleware` itself has no route information to use at creation
time (RoutingMiddleware runs later, on its own PSR-7 request clone).

| Span attribute | Source |
|---|---|
| `http.request.method`, `url.path` | The incoming request. |
| `quiote.duration_ms` | Wall time from `$_SERVER['REQUEST_TIME_FLOAT']` (falls back to `microtime(true)`) to response. |
| `quiote.cpu.user_ms`, `quiote.cpu.system_ms` | `getrusage()` deltas (start vs end) — omitted entirely if `getrusage()` isn't available (e.g. Windows). |
| `quiote.memory.peak_bytes` | `memory_get_peak_usage(true)`, reset per-request via `memory_reset_peak_usage()` so it reflects this request, not the whole worker's lifetime. |
| `quiote.memory.delta_bytes` | `memory_get_usage(true)` delta across the request. |
| `quiote.cache.hit` | `ExecutionState->cacheHit` — the same mutable object `TimingMiddleware`/`TraceMiddleware` thread through the pipeline, since PSR-7 request-attribute mutations by inner middleware aren't visible back to this outer middleware. |
| `http.response.status_code` | The returned response's status; also sets the span status to `Error` when `>= 500`. |
| `http.response.body.size` | `$response->getBody()->getSize()`, when known. |

OTel metrics recorded every request (never sampled — see Phase 4):
`http.server.request.duration` (seconds), `quiote.request.cpu.time` (seconds,
`cpu.mode` = `user`/`system`, omitted if `getrusage()` unavailable),
`quiote.request.memory.peak` (bytes), `quiote.worker.memory.rss` (bytes — a
synchronous `recordGauge()` per request, not an OTel async observable gauge;
see the plan's status notes), `http.server.request.count`. All dimensioned by
`http.response.status_code` and `cache.hit` only (no `http.route` yet, same
PSR-7-immutability reason as above).

Exception handling: wraps `$handler->handle()` in `try/catch/finally`. An
uncaught exception is recorded on the span (`recordException()` +
`setStatusError()`) and the span is ended *before* re-throwing, so
`ErrorHandlingMiddleware` (further out in the stack, priority 1000 > 950)
still renders the actual error response. Two backstops
(`ErrorHandlingMiddleware`'s own error callback, and the `Kernel::run()`
bootstrap-phase catch) also call `Trace::current()->recordException(...)` for
cases where this middleware never got to run at all.

### Route / action / view spans (Phase 6)

| Setting | Default | What it does |
|---|---|---|
| `telemetry.spans.route` | `true` | Gates BOTH the route-match span (below) and the root-span rename — disabling it skips both, not just the child span. |
| `telemetry.spans.action` | `true` | Gates BOTH the action span and its nested view-render span — there is no separate `telemetry.spans.view` setting. |

**`RoutingMiddleware`** (`Quiote.Routing` category, span name `"match"`) —
opened around the whole `process()` body (matching + attribute-setting), not
just around the downstream handler call the way `TelemetryMiddleware`'s span
wraps everything below it. Attributes: `http.route` and `route_name` on a
successful match — `http.route` is the actual Symfony path pattern (e.g.
`/orders/{id}`), fetched via `$routing->exportRoutes()[0]->get($routeName)
?->getPath()`, falling back to the route name itself if that lookup fails;
`route.matched = false` plus `route.outcome` (`404`, `405`, or
`405-options-passthrough`) on the unmatched/method-mismatch paths. **On a
successful match, also renames the root span** captured via `Trace::current()`
*before* the route-match span was opened (capturing it after would return the
route-match span itself, which is now the innermost active span — a bug the
tests guard against explicitly) to `"{METHOD} {http.route}"`.

**`ActionExecutor::execute()`** (`Quiote.Action` category, span name
`"{module}:{action}"`) — wraps the whole method (refactored into a private
`doExecute()` to give the wrapper a clean try/catch/finally without
reindenting the original body). Attributes: `quiote.module`, `quiote.action`,
`quiote.method`, `quiote.output_type`. A nested **view-render span**
(`Quiote.View` category, span name `"{viewModule}:{viewName}"`) wraps view
resolution + render (extracted into a private `renderView()`), skipped
entirely (not even a no-op) when there's no view to render (`View::NONE`).
Both spans record+rethrow on exception, same pattern as `TelemetryMiddleware`.

**Slot/sub-action spans are NOT implemented** — `SlotDispatcher::dispatch()`
is a 634-line method with a security-critical recursion guard and no single
clean try/finally to attach a span to safely; instrumenting it was judged too
risky for this pass and deliberately deferred. See
`docs/OPENTELEMETRY_PLAN.md`'s Phase 6 status notes.

### Context propagation and log correlation (Phase 7)

**No settings of its own** — entirely automatic whenever `Trace::enabled()`.

**Inbound**: `TelemetryMiddleware` extracts a W3C `traceparent`/`tracestate`
header (via `OpenTelemetry\API\Trace\Propagation\TraceContextPropagator` +
`Quiote\Telemetry\Psr7HeaderGetter`, a small bridge since PSR-7 requests
aren't array-like) and activates it *before* opening the root span, so the
root span parents onto the upstream span automatically (no API change to
`Trace::span()` needed — it already defaults to "parent = current context").
A missing/malformed/empty `traceparent` header degrades safely to starting a
fresh trace (never crashes the request) — the propagator handles this
internally, and `TelemetryMiddleware` additionally wraps the whole extraction
in `try/catch` as defense-in-depth. A remote parent explicitly marked
"not sampled" (`traceparent` flags byte `00`) is respected regardless of the
locally configured sampling ratio, via `ParentBased`'s own remote-parent
handling.

**Log ↔ trace correlation**: `TelemetryMiddleware::correlateLogContext()`
enriches `Quiote\Logging\LogContext` with `trace_id` (and `span_id`, from
`SpanHandle::traceId()`/`spanId()` — new accessors, `null` for a no-op/invalid
span) immediately after opening the root span, so every log line for the rest
of the request is cross-navigable with the trace — including a **sampled-out**
span, since trace/span IDs are generated before the sampling decision and
exist regardless of whether the span is ultimately exported. **Not** done in
`Context::handle()` (where `LogContext::enrich(['rid' => ...])` already runs,
and where the original plan sketch placed this) — at that point in the
request lifecycle the middleware pipeline hasn't run yet, so no span exists;
`TelemetryMiddleware` is the earliest point one does.

**Outbound propagation is NOT implemented** — injecting `traceparent` into
outbound HTTP client calls or DB spans needs hooks in whatever HTTP
client/`DatabaseManager` layer makes egress calls, which don't exist yet;
scoped out in the original plan and still out of scope here.

### Per-middleware spans (Phase 8)

| Setting | Default | What it does |
|---|---|---|
| `telemetry.spans.middleware` | `false` | Wraps every pipeline middleware (both the default core stack and app middleware registered via `MiddlewareCatalog::register()`) in a `Quiote\Telemetry\MiddlewareSpanDecorator`, opening a `Quiote.Middleware`-category span named by the middleware's FQCN — the same label `MiddlewarePipeline::debugStack()` already uses. High cardinality/overhead — opt-in only. When off, `MiddlewarePipeline::doBuild()` never even constructs the decorator (zero cost, not just an unused wrapper). |

Computed once per pipeline build (`Trace::enabled() && Config::get('telemetry.spans.middleware', false)`), not re-checked per middleware — safe because the pipeline is built once per worker and telemetry configuration can't change mid-worker.

**Nesting quirk worth knowing before enabling this**: `ErrorHandlingMiddleware` is the outermost middleware in the default stack, so with this setting on its wrapper span becomes the actual trace root — `TelemetryMiddleware`'s semantic `"{METHOD} {route}"` HTTP span becomes a *child* of it instead of the root. Harmless for a debugging feature, but the root span you see while this is on is a framework-internal label, not the request identity.

**Database + outbound HTTP CLIENT-kind spans are NOT implemented** — `Database::getConnection()` returns the raw driver connection directly (a bare `\PDO` for `PdoDatabase`) with no central query-execution method to instrument; building that would be a new PDO decorator/proxy layer, not an instrumentation pass over an existing seam. The framework also has no outbound HTTP client of its own to hook. Both remain deferred exactly as the original plan anticipated.

### `open-telemetry/*` packages

Declared under `composer.json`'s `require-dev` (test/dev-only — see
`docs/OPENTELEMETRY_PLAN.md`'s Dependencies section) **and** `suggest` (for a
standalone `composer require` to enable the feature in an app). A production
`composer install` without `--dev` pulls none of them; every call site that
touches an OTel class is `class_exists()`- or try/catch-guarded, so their
absence is a supported, tested state (`testDisabledByDefaultConfiguresNothing`
et al.), not just an assumption.

### `tests/e2e/` — Docker-based end-to-end verification

A real OTel Collector plus the real sample app served by real FrankenPHP
worker mode (not a unit-test simulation) — see
`docs/OPENTELEMETRY_E2E_VERIFICATION.md`. Run with `composer test:e2e`;
**excluded from `composer test`/CI** via `#[Group('e2e')]`
(`phpunit.xml`, same mechanism as the APCu tests) since it needs
Docker and real wall-clock time. Its own `tests/e2e/Dockerfile` installs
`require-dev` plus a locally-added `symfony/http-client` (the PSR-18 client
the OTLP exporter needs) — neither touches the repo's committed
`composer.json`/`composer.lock`.
