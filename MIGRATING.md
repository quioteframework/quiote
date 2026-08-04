This file covers each release that needs migration work, newest first.

- [Migrating to Quiote 3.2](#migrating-to-quiote-32) — response, request, config and PSR-7 adapter contracts
- [Migrating to Quiote 3.0](#migrating-to-quiote-30) — the session subsystem

---

# Migrating to Quiote 3.2

3.2 tightens four contracts that were quietly wrong: a response could not emit
half the status codes it needed, a request could report two different hosts, a
PSR-7 response mutated when you copied it, and configuration was a public global
array.

Most applications need no changes. The two worth grepping for are
`Config::$config` and `with*()` calls on a `PsrResponseAdapter`.

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
- [ ] Expect new warning-level log lines on the dispatch path

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
- **`exists()` is new and load-bearing.** It answers "can a write land in a
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
