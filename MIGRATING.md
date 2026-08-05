This file covers each release that needs migration work, newest first.

- [Migrating to Quiote 4.0](#migrating-to-quiote-40) — decomposing `Context`
- [Migrating to Quiote 3.2](#migrating-to-quiote-32) — response, request, config and PSR-7 adapter contracts
- [Migrating to Quiote 3.0](#migrating-to-quiote-30) — the session subsystem

---

# Migrating to Quiote 4.0

In progress. 4.0 breaks `Context` into the collaborators it was standing in for, and
its accessors are deleted rather than deprecated — an application reaching a service
through the context has to be migrated. See
[`Context`'s accessors are gone](#breaking-contexts-accessors-are-gone) for the full
table and the Rector rules that do most of it mechanically.

Clear the config cache once when upgrading; that item is first here because it fails
hard.

## The config cache now invalidates itself on a framework change

**Fixed, and it removes a whole class of upgrade hazard.** `ConfigCache` decided
freshness purely by comparing the source config file's mtime against the cache
file's. Upgrading the framework changes neither, so a cache compiled by an older
version was reused indefinitely — even when the handler that produced it now
generates a completely different shape. The failure landed at boot and reported
whatever the stale contents happened to break first, rather than the staleness.

Every cache key — both `ConfigCache`'s filenames and `APCuConfigCache`'s keys — now
includes a short framework fingerprint. It is derived from `quiote.version` plus
Composer's installed reference for `quioteframework/quiote`, which is the dist
reference for a released install and the **commit hash** for a `dev-` install. So it
changes on every framework commit, which covers developing against an unreleased
framework — something a version string alone does not.

A framework upgrade therefore recompiles automatically. Old cache files are left on
disk unused; `cache:clear` removes them.

`core.config_cache_fingerprint` is mixed in when set, so a build pipeline can force
a rebuild without touching a config file.

One layout is not covered automatically: a framework installed under a different
package name (a path repository, a vendor-less checkout), where Composer cannot be
asked for a reference. The version string alone then has to carry it, so set
`core.config_cache_fingerprint` in that case.

### Still clear the cache once, upgrading *to* 4.0

The fingerprint cannot retroactively invalidate a cache compiled before it existed.
Delete the cache directory (`core.cache_dir`, plus the system-temp fallback if
`core.cache_dir` was unset) or run `cache:warmup` once when upgrading from 3.x. From
4.0 onward it is automatic.

## The compiled `factories` file is data, not code

This is the change the cache clear is for, and it is worth understanding if you
have anything reading or generating compiled config.

The compiled `factories` file used to be executable PHP that was `include`d
*inside* `Context::initialize()`:

```php
$this->user = new SecurityUser();
$this->user->initialize($this, [...]);
$this->userFactoryInfo = ['class' => ..., 'parameters' => ...];
$this->getShutdownSequence()->replaceAll([$this->controller, ...]);
```

Included code takes on the scope it is included into, so that file had full
private access to the context, and nothing anywhere declared which properties it
was allowed to touch. Renaming or retyping any of them broke a cached file at
runtime, in the boot path, with an error naming the property rather than the stale
cache. It also meant the properties had to stay mutable and `protected`, and that
the generated output could only be tested by executing it against a stand-in
object.

It now returns a declaration:

```php
return [
    'operations' => [
        ['op' => 'build',   'role' => 'database_manager', 'class' => '...', 'parameters' => [...]],
        ['op' => 'startup', 'role' => 'database_manager'],
        // ...
    ],
    'factories' => ['response' => ['class' => '...', 'parameters' => [...]]],
    'shutdownOrder' => ['controller', 'routing', 'user', ...],
];
```

`ComponentInstaller` carries that out and hands the components back by role;
`Context` assigns them itself, by name and against a declared type. The compiled
file names configuration *roles* and cannot reach a property at all.

The operation list is ordered and the interleaving is meaningful — the database
manager starts up before the user that reads through it is built — so it is one
list rather than a list of components plus a list of startups.

**What this changes for you.** Nothing, unless you read the compiled file
directly or generated one yourself. The `factories.{xml,yaml,php}` source format
is untouched. Two internals are gone: the per-component `*FactoryInfo` properties
on `Context` (`requestFactoryInfo`, `userFactoryInfo`, …), replaced by the
declaration the lazy worker-mode rebuilds now read from, and any reliance on the
compiled file assigning into its includer.

`databases.xml` is converted the same way: the compiled file returns
`['databases' => [name => ['class' => …, 'parameters' => …]], 'default' => name]`,
`DatabaseDefinitions` validates it, and `DatabaseManager::initialize()` builds the
connections itself. Registration still happens before each connection's
`initialize()` runs, so a connection reaching for a sibling by name still finds it.

`output_types.xml` is converted too: the compiled file returns
`['outputTypes' => [name => [...]], 'default' => name]`, `OutputTypeDefinitions`
validates it, and `Controller::initialize()` constructs each `OutputType` itself. A
null `default` stays legal — a configuration may declare types without electing one.

`translation.xml` is converted too, and it was the last one. Its compiled file also
*called* a method on its includer (`$this->getContext()`), not just assigned to it —
which turned out to be the manager passing its own context to a translator, so the
manager simply does that itself now. The compiled file returns
`['defaultDomain' => …, 'defaultLocale' => …, 'defaultTimeZone' => …, 'locales' => …,
'translators' => …]`, `TranslationDefinitions` validates it, and
`TranslationManager::initialize()` builds its translators from it. Parsed locale
identifiers are still precomputed at compile time.

**No config handler emits code that touches its includer any more.** Every compiled
configuration is now data.

## Config handlers return declarations: `IDeclarationConfigHandler`

The conversions above covered the configs that are *read* — something asks for the
value and builds from it. The remaining four (`settings`, `module`, `plugins`,
`middleware`) are the configs that are *applied*: nothing reads them, they write into
the config repository or a registry. Those compiled to statements, and
`ConfigCache::load()` existed to execute them.

They are declarations now, and the code that acts on a declaration is a real method
on the handler:

```php
interface IDeclarationConfigHandler
{
    public function apply(mixed $declaration, string $sourceRef): void;
}
```

`ConfigCache::load()` reads the artifact's value and calls `apply()`. The artifact
never executes anything, so a poisoned cache entry can only produce wrong
configuration — not code execution. That is the whole point: a cache entry that is
*code* turns cache poisoning into RCE, and the APCu store is the worse half, because a
poisoned entry there never touches disk (no file-integrity monitoring, no AV, no audit
trail, and `eval`'d code is outside `open_basedir` and never appears in opcache).

Secondary benefit, already measured on the configs converted earlier: `eval`'d code is
never opcache-cached, so an unchanged config paid a full lex/parse/compile on every
single request.

**What this changes for you.** Nothing, unless you ship a config handler. The
`settings`/`module`/`plugins`/`middleware` source formats are untouched.

**If you ship a config handler**, and it is loaded with `ConfigCache::load()`, it must
now implement `IDeclarationConfigHandler`: compile a `return <data>;` artifact and move
what the generated statements did into `apply()`. A handler that does not implement it
is rejected by `load()` with an error naming the interface, rather than having its
artifact included for effect. A handler whose configuration is only *read* needs no
interface — its caller uses `CompiledConfig::value()`.

A handler that genuinely needs to *do* something at boot, rather than describe
something, should be a plugin. That is the seam intended for behaviour.

`apply()` is a trust boundary: the declaration reaches it from a cache entry or a
hand-authored `.php`/`.yaml` source, so validate the shape and throw
`ConfigurationException` rather than assuming what your own compiler produced. The
four framework handlers do.

**`module.xml` no longer reads `$moduleName` from its includer.** Its compiled file
used to expand `modules.${moduleName}.` over its own keys using a variable from the
scope it was included into. It now returns
`['enabled' => bool, 'settings' => [key => value]]` with the `${moduleName}` template
intact, and `Controller::initializeModule()` — which knows the module name — passes it
to `ModuleConfigHandler::applyDeclaration()`. `${moduleName}` inside a setting *value*
is a different mechanism and is unchanged: those sit alongside `${actionName}` and
`${viewName}`, expanded per request when an action or view is resolved.

**`validators.xml` compiles to a declaration too, and `ValidationService` applies it.** Its compiled
file was a snippet of `new X(); ->initialize(...); ->addChild(...)` statements that ran inline in
`ValidationService`'s own scope, reading the free variables `$validationManager`/`$method` and calling
`$this->getContext()`. It now returns

```php
return ['buckets' => [
    ''      => ['declaredParameters' => [...], 'validators' => [ /* specs, in registration order */ ]],
    'write' => ['declaredParameters' => [...], 'validators' => [...]],
]];
```

and `Quiote\Validator\Compiler\Runtime\ValidatorDeclarationApplier` builds the validators from it. The
per-method `if ($method == '…')` blocks the artifact used to emit are buckets: the applier applies the
methodless bucket and the one matching the request method. Nested (and/or/not/xor) children name their
parent instead of relying on a generated variable being in scope.

`Quiote\Validator\Compiler\RuntimeArrayEmitter` is gone, replaced by `RuntimeDeclarationEmitter`, which
returns the declaration rather than snippet lines. `ValidatorPlan`/`ValidatorNode` — the IR both are
built from — are unchanged, as is `FluentSourceEmitter`. The private closure cache in
`ValidationService` that existed only to avoid re-`eval()`ing that snippet is gone with it; the APCu
cache now serves the declaration's value from shared memory instead.

**The `plugins` merge is testable code now.** Appending only classes not already
present used to exist solely as a generated string. It is
`PluginConfigHandler::merge()`, with the same guarantees: declared order preserved,
first occurrence across all contributing files wins, app before modules.

## `execute()` returns the declaration; the cache serializes it

The last step of the same change: a handler no longer produces PHP source at all.

- `IXmlConfigHandler::execute()`, `IArrayConfigHandler::executeArray()` and
  `ILegacyConfigHandler::execute()` return `mixed` — the declaration — instead of `string`.
- `BaseConfigHandler::generate()` is **removed**. A handler that called it to wrap its
  `var_export()` in a cache-file header now just returns the value. The serializing lives
  in `Quiote\Config\CompiledArtifact::source()`, which the cache calls.
- `ConfigCache::writeCacheFile()` takes the value and an optional handler-class label:
  `writeCacheFile(string $config, string $cache, mixed $value, ?string $generatedBy = null)`.
  The `$append` parameter is gone — appending fragments of source has no meaning for a
  value, and nothing in the framework used it.
- A declaration containing an object, closure or resource is refused at the write, naming
  the offending key path, instead of producing a file that cannot reproduce it.

**`APCuConfigCache` now stores the value itself**, not compiled source, which is what
removes the last `eval()` from the configuration cache. Consequences:

- The `'APCU:'` marker is gone. `APCuConfigCache::checkConfig()` no longer returns a path
  or a marker — there is no file — and throws to say so; read compiled configuration with
  `CompiledConfig::value()`.
- A value shared memory cannot reproduce faithfully falls back to the file cache rather than
  being served as a broken clone.
- `cache:warmup` stores values, so a warmed worker never compiles at all.

**What this changes for you.** Nothing, unless you ship a config handler or call
`writeCacheFile()`/`APCuConfigCache::checkConfig()` directly.

## BREAKING: `Context`'s accessors are gone

`ContextInterface` declares two methods — `getName()` and `getContainer()` — where 3.2
declared seventeen, and `Context` itself is down from 39 public methods to 17. Every
accessor that answered "some other service" has been deleted. A class that needs the
routing, the user or a service says so in its constructor and lets the container hand it
over; the ones that genuinely cannot be wired statically resolve through
`getContainer()`.

Each row's target is bound in the container under the class name shown, so
`__construct(private readonly Routing $routing)` is the migration for most of them.

| Deleted | Inject | Notes |
|---|---|---|
| `getRouting()` | `Quiote\Routing\Routing` | rebuilt on demand in a worker, as the accessor did |
| `getController()` | `Quiote\Controller\Controller` | resolving one before `initialize()` throws, as before |
| `getRequest()` / `setRequest()` | `Quiote\Request\RequestState` | `current()` / `publish()`; resolves per call — see below |
| `getUser()` | `Quiote\User\User`, or `Quiote\User\CurrentUser` | which one depends on the holder's lifetime — see below |
| `getService($id)` | the service's own class | the container resolves it; there is no by-name lookup left |
| `getModel(…)` | `Quiote\Model\ModelLocator` | also `$context->getModelLocator()` |
| `getDatabaseManager()` / `getDatabaseConnection()` | `Quiote\Database\DatabaseManager` | `getConnection()` on the manager |
| `getTranslationManager()` | `Quiote\Translation\TranslationManager` | |
| `getSessionManager()` / `setSessionManager()` | `Quiote\Session\SessionManager` | `Container::set()` / `unset()` to replace or drop one |
| `getSessionBag()` / `setSessionBag()` | `Quiote\Session\SessionBagInterface` | defaults to `NullSessionBag` when no session is configured |
| `getSlotDispatcher()` | `Quiote\Execution\SlotDispatcher` | request-scoped |
| `getAssetRegistry()` | `Quiote\Asset\AssetRegistry` | request-scoped |
| `getActionResolver()` | `Quiote\Execution\ActionResolver` | process-lifetime singleton |
| `getCurrentPsrRequest()` | `Quiote\Request\RequestState` | `current()` |
| `createInstanceFor()` | `Container::make()` | generic in the class it is given |
| `getFactoryInfo()` / `setFactoryInfo()` | — | the compiled factories declaration is internal now |
| `handle()` | `getRequestHandler()->handle()` | see below |

`Quiote\Rector\Set\ContextDecompositionSetList` mechanically rewrites the common call
shapes — the routing, the request, the user, the translation manager, the database
manager and `getService()` — and reports every site it declines in a residue file for a
human to look at. Run it before migrating by hand.

### `Context::handle()` is gone; take the PSR-15 handler instead

```php
$response = $context->getRequestHandler()->handle($request);
```

The per-request work — owning the middleware pipeline, resolving the correlation id,
opening the ambient logging scope, arming the request-state flush, emitting
`ResponseSendingEvent` — lives in `Quiote\Runtime\ContextRequestHandler`, which
**declares** `RequestHandlerInterface` rather than merely matching its signature.
`getRequestHandler()` is typed as that PSR interface, so a runtime can serve a context
through a handler of its own by wiring rather than by subclassing.

Two internals moved with it:

- `Context::$psrKernel` is gone. Reach the pipeline with `pipeline()` on the handler,
  and drop a stale one with `forgetPipeline()` — needed by anything that reconfigures
  `MiddlewareCatalog` after a request has been served, since the pipeline is composed
  once and reused. Both live on `ContextRequestHandler`, so narrow to it first.
- `Context::$correlationId` is gone; `getCorrelationId()` reads it from the handler.

### What `Context` still answers

Its own identity and lifecycle, which were never anyone else's: `getName()`,
`getContainer()`, `getInstance()`, `create()`, `initialize()`, `shutdown()`, `reset()`,
`resetWorkerState()`, `beginRequest()`, `flushRequestState()`, `getCorrelationId()`,
`getRequestHandler()`, `getLifecycle()`, `getShutdownSequence()` and
`getModelLocator()`.

### The execution helpers are container-scoped now

`getSlotDispatcher()`, `getAssetRegistry()` and `getActionResolver()` resolve through
the container instead of lazy properties, so their lifetimes are declared rather than
maintained by hand: the action resolver is a process-lifetime singleton, and the asset
registry and slot dispatcher are request-scoped, so the container drops them at the
request boundary — which two manual nulls in `reset()` used to do. All three are also
injectable now (`AssetRegistry`, `SlotDispatcher`, `ActionResolver`, or `assetRegistry`
/ `slotDispatcher` / `actionResolver`).

### Which one to inject for the request and the user

They look alike and are not.

**The user is stable within a request.** It is replaced only at the worker request
boundary, never mid-request, so anything built per execution — an action, a view —
can inject `SecurityUser` (or `User`, or `ISecurityUser`) and hold it:

```php
public function __construct(private readonly SecurityUser $user) {}
```

**The request is not.** `WebRequest` is immutable, so every mutation produces a new
instance and the request is replaced many times per request — validation alone
replaces it. A held request is a snapshot, and a construction-time snapshot is the
*pre-validation* one, so reading a parameter from it bypasses the strict-validation
whitelist. Inside an action or view use the `WebRequest` parameter already passed to
`execute*()`; it is current by construction.

**A singleton can hold neither**, and the container refuses that wiring outright: it
would serve request 1's request or user to every later request in a worker. Inject
`RequestState` or `CurrentUser` there. Both resolve through on every call and hold
nothing.

## New: `ContextLifecycle`, and plugins can hook the end of a request

`Quiote\ContextLifecycle` owns a context's per-request state machine — armed,
claimed, cleared, armed again. Reach it with `Context::getLifecycle()`.

It holds two things that only make sense together: the **state-flush claim**
(exactly one caller per request persists the session-backed state; the first wins
and the rest are no-ops rather than double writes) and the **end-of-request
clears** that drop everything which must not survive into the next request served
by the same process.

Anything holding request-scoped state of its own — a per-request cache, a memo
keyed on the current user — previously had no way to clear it, so that state
survived into the next request in a persistent worker:

```php
PluginManager::addRequestEndClear('my per-request cache', function (): void {
    MyCache::forgetRequestState();
});
```

Contributed clears run after the framework's own, so a plugin cannot displace the
identity clears (session bag, user, request) that go first. Each clear is
independently guarded: one that throws is logged and stepped over, and every other
clear still runs — including the re-arm afterwards, so a broken clear cannot cost
the next request its claim.

## Validators can declare constructor dependencies

Validator construction goes through the container, so a validator may take
collaborators like anything else:

```php
final class VatNumberValidator extends Validator
{
    public function __construct(private readonly VatLookupService $lookup) {}

    protected function validate(): bool { /* ... */ }
}
```

Purely additive. A validator with no constructor — every validator the framework
ships, and every one written before this — is `new`'d directly, so nothing about
the existing path changes.

Parameters, argument names and error messages still arrive through
`initialize()`. Those are per-declaration *data* read out of a config file, not
collaborators, so there is nothing for the container to resolve them from.

A validator is built per validation and never cached, so it may also depend on
request-scoped state (`WebRequest`, the user) directly — unlike a singleton
service, which cannot.

`Container::make()`, `ValidationManager::createValidator()` and
`ValidatorFactory::create()` are now generic in the class they are given, so a
caller naming a concrete class gets that type back instead of `object`.

## Fixed: injecting `WebRequest` or `User` gave you a fresh, empty one

A defect, not a rename. The container bound each core service under its role name
and its *concrete* class only. An application configures a `request` or `user`
subclass, so the natural type-hint — `WebRequest`, `User` — was unregistered, and the
container autowired a brand-new instance for it. A consumer asking for the request
got one carrying none of the request's parameters, headers or body; one asking for
the user got an unauthenticated stranger. Silently, in both cases.

The base classes are now bound alongside the concrete class, so `WebRequest`, `User`,
`ISecurityUser`, `Routing`, `TranslationManager` and `DatabaseManager` all resolve to
the request's real instance.

If you worked around this — resolving `'request'` by string, or type-hinting the
subclass to get the real object — those still work and can now be simplified. If you
type-hinted the base class in a **singleton**, that wiring was silently broken and
now throws at wiring time, naming the accessor to use instead.

Two things that were private are now public API, because a test or an embedding
host had no honest way to reach them:

- `Context::getShutdownSequence()` replaces reflection on the `$shutdownSequence`
  property, which is no longer an array. Use `append()`, `remove()`,
  `replaceRole()` and `all()`.
- `Context::create()` is the named constructor `ContextRegistry` builds through. A
  subclass named by `core.context_implementation` must keep the constructor
  signature — that was always true, and is now declared.

---

# Migrating to Quiote 3.2

3.2 tightens contracts that were quietly wrong: a response could not emit half the
status codes it needed, a request could report two different hosts, a PSR-7 response
mutated when you copied it, configuration was a public global array, a filesystem
interface declared an operation three of its four implementations refused, and the
session wire format had seven implementations that disagreed with each other.

Most applications need no changes. The three worth grepping for are
`Config::$config`, `with*()` calls on a `PsrResponseAdapter`, and imports of a
provider-local `ObjectMetadata`.

---

## 1. `WebResponse` accepts the full status range

`validateHttpStatusCode()` tested membership of a hardcoded ~35-entry
per-protocol table, and `setHttpStatusCode()` threw on a miss. 422, 429, 308,
451, 507 and 511 were unsettable, so any code composing a response through
`WebResponse` could not emit them. `View::returnProblemDetailsFromValidationIncidents()`
is the case that bit: called with 422 it produced a document reporting 422 and
served it as `200 OK` with no `application/problem+json` content type, because the
setter threw and its catch swallowed it.

Validity now comes from `Quiote\Http\HttpStatus`: any code in 100–599.

**If you relied on rejection**, narrow it explicitly in a subclass. The framework
never sets this property:

```php
class StrictResponse extends WebResponse
{
    /** @var ?array<int, string> */
    protected $httpStatusCodes = ['200' => 'OK', '404' => 'Not Found'];
}
```

**If you match on the exception message**, it changed and no longer names a
protocol:

| Before | After |
|---|---|
| `Invalid HTTP/1.1 Status code: 999` | `Invalid HTTP status code: 999 (expected 100-599)` |
| `Invalid HTTP/1.1 Redirect Status code: 999` | `Invalid HTTP redirect status code: 999 (expected 100-599)` |

The protocol-derived table selection is gone entirely. It fell through to the
HTTP/1.0 list for anything that was not literally `HTTP/1.1` or `HTTP/2`, so on
HTTP/3 — or whenever `getProtocol()` answered null — ordinary codes like 303 and
307 were unsettable.

`WebResponse::$http10StatusCodes` and `$http11StatusCodes` are deprecated and no
longer consulted. They remain as protected properties for subclasses that read
them.

---

## 2. `PsrResponseAdapter` is immutable

**This is the change most likely to affect application code.**

Every `with*()` method used to mutate the wrapped `WebResponse` and return
`$this`. The adapter is handed to views and actions by `ViewFactory`,
`ActionExecutor` and `ImmutableViewInitContext`, so the ordinary PSR-7 idiom
silently changed the shared response — and a caller holding the original to
compare against found it altered.

`with*()` now clones and leaves both the adapter and the `WebResponse`
untouched, as `ResponseInterface` requires.

**Before** — worked by side effect, return value discarded:

```php
public function executeJson(WebRequest $rd)
{
    $psr = $this->getInitContext()->getPsrResponse();
    $psr->withHeader('X-Thing', '1');   // now a no-op
}
```

**After** — write to the response that gets sent:

```php
public function executeJson(WebRequest $rd)
{
    $this->getResponse()->setHttpHeader('X-Thing', '1');
}
```

From code holding an adapter rather than a view, `getLegacy()` is still the
mutable response:

```php
$adapter->getLegacy()->setHttpHeader('X-Thing', '1');
```

Two smaller corrections in the same class: `withStatus()` validates the code and
throws `InvalidArgumentException` as PSR-7 mandates (it previously threw
`QuioteException`, which is not an `InvalidArgumentException`), and
`getReasonPhrase()` returns the real phrase instead of the empty string.

A discarded `with*()` return value is now a no-op rather than a hidden mutation,
which is exactly the failure this fixes — so grep for `->with` on anything
reached through `getPsrResponse()`.

---

## 3. `Config::$config` is private

Configuration was a `public static` array, so anything could write to it and no
consumer could be handed a different one. `Quiote\Config\ConfigRepository` now
holds the behaviour as an ordinary object; `Config` keeps its whole static API
and delegates.

**Every `Config::get*()`, `set()`, `has()`, `remove()`, `fromArray()`,
`toArray()`, `clear()` and `resetWorkerState()` call is unchanged.** Only direct
property access breaks:

| Before | After |
|---|---|
| `Config::$config['k'] = $v` | `Config::set('k', $v)` |
| `unset(Config::$config['k'])` | `Config::remove('k')` |
| `Config::$config['k'] ?? $d` | `Config::getString('k', $d)` (or the matching typed accessor) |
| `isset(Config::$config['k'])` | `Config::has('k')` |
| `foreach (Config::$config as ...)` | `foreach (Config::toArray() as ...)` |
| `new ReflectionProperty(Config::class, 'config')` | the accessors above |

This is mechanically rewritable and a good Rector target.

Two things the object buys you. A service can declare the dependency instead of
reaching for the facade — the container binds it under `config` and its class
name:

```php
final class Thing
{
    public function __construct(private readonly ConfigRepository $config) {}
}
```

And a test can install a configuration of its own and put back what was there:

```php
$previous = Config::useRepository(new ConfigRepository(['core.debug' => true]));
try {
    // ...
} finally {
    Config::useRepository($previous);
}
```

One documentation correction while we were in there: `fromArray()`'s precedence
is a read-only directive first, then the imported data, then an existing
directive the import does not mention. The old comment described the operand
order the other way round. The behaviour did not change.

---

## 4. `ValidationMiddleware` requires a `Controller`

The constructor took `?Controller $controller = null` and then resolved one from
the `'web'` context by name when it was absent — which pinned the framework to a
single context profile, and wrote to a property on an instance the pipeline
caches for the worker's lifetime, so the first request's controller was reused by
every later one.

```php
// Before
new ValidationMiddleware();
new ValidationMiddleware(null);

// After
new ValidationMiddleware($controller);
```

Only relevant if you construct the middleware yourself. The pipeline already
passes one.

---

## 5. `WebRequest` URL mutators

The seven `setUrlScheme()`, `setUrlHost()`, `setUrlPort()`, `setRequestUri()`,
`setUrlPath()`, `setUrlQuery()` and `setProtocol()` methods wrote only
`WebRequest`'s own URL metadata and left the wrapped PSR-7 URI alone. After
`setUrlHost('other.test')`, `getUrlHost()` and `getUri()->getHost()` answered
differently — so a host- or scheme-based check passed or failed depending on
which of the two the caller happened to read.

**All seven keep working and keep their `void` signature.** They now also rewrite
the wrapped URI, so `getUri()` reflects the change where it previously did not.
If you have a check reading `getUri()` after one of these calls, it now sees the
new value.

They are deprecated in favour of `with*()` counterparts that return a new
instance:

| Deprecated | Preferred |
|---|---|
| `$r->setUrlScheme('https')` | `$r = $r->withUrlScheme('https')` |
| `$r->setUrlHost('h')` | `$r = $r->withUrlHost('h')` |
| `$r->setUrlPort(8443)` | `$r = $r->withUrlPort(8443)` |
| `$r->setRequestUri('/p?a=b')` | `$r = $r->withRequestUri('/p?a=b')` |
| `$r->setUrlPath('/p')` | `$r = $r->withUrlPath('/p')` |
| `$r->setUrlQuery('a=b')` | `$r = $r->withUrlQuery('a=b')` |
| `$r->setProtocol('HTTP/1.0')` | `$r = $r->withProtocol('HTTP/1.0')` |

Rewritable by Rector, but the rewrite has to capture the return value — a
mechanical `set` → `with` rename that drops it turns a working call into a
silent no-op. Convert with the assignment or leave the setters alone.

---

## 6. The session wire format has one codec

Seven backends serialized session payloads their own way, and the three that used
igbinary disagreed on how to recognise it coming back: core's file backend sniffed
igbinary's format header, while `session-pdo` and `session-redis` tested for the
payload not starting with `{` or `[`. A payload satisfying one test and not the other
was read differently depending on which backend held it. The other four
(`session-s3`, `session-gcs`, both Azure backends) were JSON-only with no stated
reason.

`Quiote\Session\SessionCodec`, behind `SessionCodecInterface`, is now the single
implementation. One discriminator: a payload beginning with `{` or `[` is JSON,
anything else is offered to igbinary. Decoding accepts both formats whichever it
writes, so a payload written by one backend stays readable by another.

**No change if you configure sessions through the `session` factory slot.** Each
backend defaults to the codec appropriate for it — igbinary for file and database
stores, JSON for object stores, where the round-trip dominates and a readable stored
object is worth more than a compact one.

**If you construct a persistence backend directly**, the codec is the last
constructor argument, and it defaults:

```php
use Quiote\Session\SessionCodec;

// session-pdo (Quiote\Session\Pdo\PdoSessionPersistence)
new PdoSessionPersistence($pdo, 'session');                            // unchanged
new PdoSessionPersistence($pdo, 'session', SessionCodec::portable());  // explicit

// core (Quiote\Session\PdoSessionPersistence) — takes a parameter array
new PdoSessionPersistence($pdo, ['table' => 'session']);
new PdoSessionPersistence($pdo, ['table' => 'session'], SessionCodec::portable());
```

Only positional arguments *past* the documented ones are affected.

Implement `SessionCodecInterface` to change the stored form — encryption at rest, a
compressed envelope, a format an external consumer already reads — and hand it to the
backend.

One pre-existing limitation is now explicit: a top-level session key that PHP coerces
to an integer (`$bag->set('0', …)`) cannot round-trip, because the decoded array is
then a list rather than session data. That is a property of PHP's array keys, not of
the encoding, and every previous implementation behaved the same way. Session keys
have to be non-numeric strings.

---

## 7. `listContents()` is no longer on `FilesystemAdapterInterface`

The interface declared it and three of four implementations threw from it
unconditionally: the S3, GCS and Azure adapters are built on single-object REST calls
with no list endpoint. Code holding the interface could not call the method without
knowing which adapter it actually had.

It moves to `Quiote\Filesystem\ListableFilesystemInterface`, which extends the base
contract. `LocalFilesystemAdapter` implements it; the cloud adapters no longer declare
a method they cannot honour.

| Before | After |
|---|---|
| `$adapter->listContents()` on a `FilesystemAdapterInterface` | type-hint `ListableFilesystemInterface` |
| — | or `$manager->listContents()` / `$manager->listableDisk()` |

`FilesystemManager::listableDisk()` resolves the configured driver narrowed to the
listable contract and, when it is not one, fails naming the disk alias and the driver
class — at the point the disk is resolved rather than from inside the call.

**If you implement `FilesystemAdapterInterface` yourself**, nothing breaks; you may
now drop `listContents()`. **If your adapter does support listing**, declare
`ListableFilesystemInterface` so `FilesystemManager` can resolve it.

This is the same shape `Quiote\Queue\PollableQueueDriverInterface` already uses: not
every driver can poll, not every store can enumerate.

---

## 8. One `ObjectMetadata` for every object store

`S3Client`, `GcsClient` and `AzureBlobClient` exposed the same operation set as three
classes sharing no interface, so nearly everything downstream was written three times
— including three byte-identical metadata value objects differing only in namespace.

`Quiote\Storage` now holds the shared contract: `ObjectStoreClientInterface`, one
`ObjectMetadata`, and `ObjectStoreException` as the supertype of each provider's own
exception.

| Removed | Use |
|---|---|
| `Quiote\Storage\S3\ObjectMetadata` | `Quiote\Storage\ObjectMetadata` |
| `Quiote\Storage\Gcs\ObjectMetadata` | `Quiote\Storage\ObjectMetadata` |
| `Quiote\Storage\Azure\BlobMetadata` | `Quiote\Storage\ObjectMetadata` |

The class is otherwise identical — same constructor, same `fromResponse()`, same three
nullable fields — so a `use` statement is the whole migration. Mechanically
rewritable, and a good Rector target. `AzureBlobClient::head()` returns the shared
type.

**The provider exceptions still exist and still narrow.** `S3StorageException`,
`GcsStorageException` and `AzureStorageException` now extend `ObjectStoreException`, so
`catch (S3StorageException)` keeps working *and* code written against the interface can
catch one type across providers.

**The six provider adapters keep their names, namespaces and constructor signatures** —
`S3FilesystemAdapter`, `GcsFilesystemAdapter`, `AzureFilesystemAdapter`,
`S3SessionPersistence`, `GcsSessionPersistence`, `AzureBlobSessionPersistence`. Driver
aliases, `session` slot config and DI bindings are untouched; the shared behaviour
moved to `Quiote\Filesystem\ObjectStoreFilesystemAdapter` and
`Quiote\Session\ObjectStoreSessionPersistence` behind them.

New: `AzureBlobContainerClient` binds an `AzureBlobClient` to one container so it
satisfies `ObjectStoreClientInterface` like the other two, since Azure takes the
container per call.

---

## Behaviour changes that need no code edit, but will be noticed

**A view's attributes now converge on one store.** `View::initialize()` always
populated an internal attribute store, but only `setAttribute()` and
`getAttributes()` read it; every other accessor went to the init context's
holder. The two never merged, so `setAttribute('k', $v)` was invisible to
`getAttribute('k')`, `appendAttribute()` silently did nothing under the modern
execution path, and `getAttribute('k', $default)` returned null instead of
`$default`. All of that now behaves as the names promise. Code that worked around
the old split — reading a value back through `getAttributes()` because
`getAttribute()` returned nothing — still works, but the workaround is no longer
needed.

**`Set-Cookie` serialization has one implementation.** Two divergent ones existed
(the response's own and `Quiote\Http\CookieSerializer`), differing in default
value encoding, deletion handling and date formatting. Cookies queued on a
`WebResponse` are unaffected — that path's semantics were kept. The bridging path
used by `DispatchMiddleware` keeps its defensive skipping of a malformed cookie
definition, so nothing there changes either.

**Failures on the dispatch path are logged instead of vanishing.** Status,
headers, redirects and cookies dropped while bridging the global response onto
the PSR-7 response now log a warning, and a lost `Set-Cookie` logs at error
level. Expect new log lines where something was already going wrong silently.

---

## New contracts, no migration required

`ContextInterface`, `ControllerInterface`, `WebResponseInterface` and
`ValidatorInterface` are implemented by `Context`, `Controller`, `WebResponse`
and `Validator`, and bound in the container so a service can type-hint the
contract:

```php
public function __construct(private readonly ContextInterface $context) {}
```

`Quiote\ContextComponentInterface` types the `initialize()`/`startup()` pair on
`WebRequest`, `User`, `Routing` and `DatabaseManager`.

All of it is additive. No existing signature changed, and the interfaces declare
no PHP return types where the implementations declare none, so subclasses stay
compatible whether or not they declare one.

### `TelemetryBootstrap` is decomposed, with its API unchanged

Settings resolution, exporter construction and provider assembly move out of the
static bootstrap module into `TelemetryConfig`, `TelemetryExporterFactory` and
`TelemetryProviderFactory`. `TelemetryBootstrap` keeps only what has to be
process-wide — configured-once, the registered shutdown function, request-boundary
flushing, and `reset()`.

Its whole public static API is unchanged, so `Kernel::bootstrap()` and any code
calling `configureFromConfig()`, `flushAfterRequest()`, `shutdown()`, `reset()`,
`inMemorySpanExporter()` or `inMemoryMetricExporter()` needs no edit. What is new is
that provider assembly can be exercised directly, over an in-memory exporter, without
OTLP configuration or bootstrap state:

```php
$config = new TelemetryConfig(/* … */ exporter: 'none', /* … */);
$exporters = new TelemetryExporterFactory($config);
$providers = new TelemetryProviderFactory($config, $exporters);

$tracerProvider = $providers->tracerProvider($providers->resource());
```

---

## Checklist

- [ ] Grep for `Config::$config` and rewrite to the accessors
- [ ] Grep for `->with*()` on anything from `getPsrResponse()` or a
      `PsrResponseAdapter`; move the intent to `getResponse()`/`getLegacy()`
- [ ] Grep for `catch` blocks matching on `Invalid HTTP/1.1 Status code`
- [ ] Check subclasses reading `$http10StatusCodes` / `$http11StatusCodes`
- [ ] Pass a `Controller` if you construct `ValidationMiddleware` yourself
- [ ] Check host- or scheme-based checks that read `getUri()` after a
      `setUrl*()` call
- [ ] Rewrite `Quiote\Storage\{S3,Gcs}\ObjectMetadata` and
      `Quiote\Storage\Azure\BlobMetadata` imports to `Quiote\Storage\ObjectMetadata`
- [ ] Type-hint `ListableFilesystemInterface` (or use
      `FilesystemManager::listContents()`) anywhere you call `listContents()`
- [ ] Declare `ListableFilesystemInterface` on your own adapters that support listing
- [ ] Check for positional arguments past the documented ones if you construct a
      session persistence backend directly
- [ ] Check for session keys that PHP coerces to integers (`'0'`, `'1'`)
- [ ] Expect new warning-level log lines on the dispatch path and at the worker
      request boundary (ORM cleanup, object-store failures)

---

# Migrating to Quiote 3.0

3.0 replaces the session subsystem. The ext/session–backed `storage` component
is gone; sessions are now PSR-7-native, which is what makes them behave
correctly under long-lived worker runtimes.

Most applications need three changes: swap one factory slot, replace
`getStorage()` calls, and accept that everyone is logged out once.

---

## Why

Under FrankenPHP, RoadRunner and Swoole the old stack had defects that were not
implementation slips but consequences of forcing ext/session — a single global
session per process, a cookie emitted through `header()` — into a PSR-7 request
pipeline in a process that serves many requests.

The visible symptoms were a login that returned 302, logged success, and then
403'd on every following request; a session row written for every request that
reached the framework, health checks and bots included; and `SQLITE_BUSY` under
load.

All three are fixed, and most of the root causes are now structurally impossible
rather than merely repaired. There is no process-global session id, so nothing
can leak from one worker request into the next. There is no
`SessionHandlerInterface`, so no callback can re-enter the function that invoked
it. `save()` is an ordinary write with no relationship to `headers_sent()`, so a
late write lands instead of silently vanishing. And the cookie rides the PSR-7
response rather than PHP's output layer, so nothing has to synthesise it
off-SAPI.

Verified against real RoadRunner, Swoole and FrankenPHP servers.

---

## 1. Replace the `storage` slot with a `session` slot

**Before**

```yaml
storage:
  class: Quiote\Storage\PdoSessionStorage
  params:
    database: sessions
    db_table: session
```

**After**

```yaml
session:
  class: Quiote\Session\PdoSessionFactory
  params:
    database: sessions
    table: session
```

The zero-dependency default needs no database:

```yaml
session:
  class: Quiote\Session\FileSessionFactory
  params:
    dir: '%core.app_dir%/cache/sessions'
```

Cookie settings move onto the same slot: `cookie_name`,
`session_cookie_lifetime`, `session_cookie_secure`, `session_cookie_httponly`,
`session_cookie_samesite`, `session_migration_grace_seconds`.

### If you had `NullStorage`

Delete the slot. A context with no `session` entry gets a `NullSessionBag`:
reads return their default, writes are discarded, `exists()` is false. That is
the right shape for a console command, a queue worker or a stateless API, and it
is what `NullStorage` expressed before.

The `session` slot is **optional**. Nothing forces a session backend on a
context that has no use for one.

### Available backends

Every backend ships a `session` slot factory. Name one and configure it; there
is no wiring to write.

| Backend | Factory | Package |
|---|---|---|
| Files | `Quiote\Session\FileSessionFactory` | core |
| PDO | `Quiote\Session\PdoSessionFactory` | core |
| Redis | `Quiote\Session\Redis\RedisSessionFactory` | `session-redis` |
| S3 | `Quiote\Storage\S3\S3SessionFactory` | `session-s3` |
| GCS | `Quiote\Storage\Gcs\GcsSessionFactory` | `session-gcs` |
| Azure Blob | `Quiote\Storage\Azure\AzureBlobSessionFactory` | `session-azure` |
| Azure Table | `Quiote\Storage\Azure\AzureTableSessionFactory` | `session-azure` |

The S3, GCS and Azure factories expect a PSR-18 client bound in the container,
the same contract the matching `filesystem-*` packages use.

A custom backend implements `Quiote\Session\SessionPersistenceInterface`
(`load`/`save`/`delete`) plus a `Quiote\Session\SessionFactoryInterface` to
build it, and can then be named in the slot like any other.

---

## 2. Replace `Context::getStorage()` with `Context::getSessionBag()`

`SessionBagInterface` is narrower and more explicit than `Storage` was.

| Before | After |
|---|---|
| `$context->getStorage()->retrieve($k)` | `$context->getSessionBag()->get($k)` |
| `$context->getStorage()->retrieve($k) ?? $d` | `$context->getSessionBag()->get($k, $d)` |
| `$context->getStorage()->store($k, $v)` | `$context->getSessionBag()->set($k, $v)` |
| `$context->getStorage()->remove($k)` | `$context->getSessionBag()->remove($k)` |
| `$storage->regenerate(true)` | `$bag->regenerate(true)` |
| — | `$bag->has($k)`, `$bag->exists()`, `$bag->getId()`, `$bag->destroy()` |

Two differences worth knowing:

- **`get()` normalizes "missing".** `SessionStorage::retrieve()` answered `null`
  and `NullStorage::retrieve()` answered `false`; code only survived that through
  loose comparison. `get()` returns your `$default` for both.
- **`exists()` is new, and consulting it matters.** It answers "can a write land in a
  session that already exists?" Consult it before persisting default or empty
  state, so an anonymous or stateless request does not acquire a session it never
  asked for. A deliberate write — a login, a user preference — should not consult
  it.

### Removed classes

`Quiote\Storage\Storage`, `NullStorage`, `SessionStorage`, `PdoSessionStorage`,
`Quiote\Storage\Pdo\PdoSessionStorage`,
`Quiote\Runtime\Session\NativeSessionCookieBridge`. `WorkerLoop`'s constructor no
longer takes a `sessionCookies` argument.

`Quiote\Middleware\SessionMiddleware` still exists under the same FQCN, so
middleware ordering config and `before:`/`after:` anchors keep resolving. It now
drives the configured backend.

---

## 3. Everyone is logged out once

Old `$_SESSION` payloads are not migrated to the new backend. There is no
converter and there will not be one: it is a large amount of serialization
archaeology for a one-time event. Plan the deploy accordingly.

---

## Behaviour changes that need no code edit, but will be noticed

**Anonymous requests no longer create a session or emit a cookie.** A request
that touches nothing costs nothing. A visitor who *does* write something — a
language preference, a cart, an anonymous CSRF token — still gets a session, and
it still sticks. Only code that assumed *every* visitor already has a session id
is affected; give it an explicit write.

**Logging out invalidates the session.** `setAuthenticated(false)` now discards
the session contents and rotates the id. Previously it recorded
`authenticated=false` and left the id valid and replayable. Anything relying on
data surviving a logout must move that data elsewhere.

**User state is persisted earlier.** It is written before the response is
emitted, not after. Anything mutating the user *after* the pipeline unwind — late
middleware below `SessionMiddleware`, a worker-completed listener — no longer
persists and must move above `SessionMiddleware`.

**Sessionless requests persist nothing.** A request marked `auth.sessionless` or
`jwt.skip_session` no longer writes user state at all.

---

## If you subclass `User`, `SecurityUser` or `RbacSecurityUser`

**This is the change most likely to break you silently.**

The user hierarchy now tracks whether a request actually changed anything and
writes nothing when it did not. A subclass that mutates `$attributes`,
`$credentials` or `$roles` *directly*, or overrides a mutator without calling
`parent::`, is invisible to that tracking and will stop persisting — with no
error.

```php
// Invisible to dirty tracking:
$this->attributes[$ns]['userId'] = $id;

// Either go through the mutator:
$this->setAttribute('userId', $id, $ns);

// or say so explicitly:
$this->attributes[$ns]['userId'] = $id;
$this->markDirty();
```

`markDirty()` is public and exists for exactly this. `isDirty()` and
`markClean()` round out the API.

Audit for direct writes to those three properties before upgrading.

---

## Session fixation

`SessionManager::regenerate()` migrates the old id rather than deleting it
outright, so a request already in flight with the pre-rotation cookie is not
silently logged out. That window is much tighter than a plain grace period:

- the redirect tombstone is consumed on first use, so it rescues one request
  rather than every request in the window;
- it is bound to the requesting client;
- it is skipped entirely when the pre-login session was empty — the ordinary
  anonymous-to-authenticated login — which therefore has no window at all;
- the default grace is 5 seconds (`session_migration_grace_seconds`).

`SessionManager::regenerate()` and `migrateOld()` take an additional optional
request argument. This breaks subclasses overriding them.

---

## Also in 3.0

**A FrankenPHP Dockerfile fix worth copying.** `dunglas/frankenphp` reads
`/etc/frankenphp/Caddyfile`, not `/etc/caddy/Caddyfile`. An image copying its
Caddyfile to the latter has it silently ignored and starts in classic mode
rather than worker mode. Check your own Dockerfile.

---

## Checklist

- [ ] Replace the `storage` slot with a `session` slot in every
      `factories.{yaml,xml,php}`, or delete it for contexts with no session
- [ ] Replace `getStorage()` with `getSessionBag()` and map the method names
- [ ] Grep for direct writes to `$attributes`, `$credentials`, `$roles` in `User`
      subclasses; add `markDirty()` where needed
- [ ] Move any post-unwind user mutation above `SessionMiddleware`
- [ ] Check for code assuming every visitor has a session id
- [ ] Check for anything relying on session data surviving logout
- [ ] Drop `sessionCookies:` if you construct `WorkerLoop` yourself
- [ ] Plan for a one-time logout on deploy
