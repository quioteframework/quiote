# Injecting custom middleware into the pipeline

Quiote builds a single PSR-15 middleware pipeline
(`Quiote\Middleware\MiddlewarePipeline`) once per worker and reuses it across
requests. There are two ways for an application to add its own middleware,
and they can be mixed freely:

1. **By code** — `Quiote\Middleware\MiddlewareCatalog::register()`, giving an
   explicit factory and `before:`/`after:`/`priority:` hints. See [API](#api)
   below.
2. **By attribute** — decorate the class with `#[Quiote\Middleware\Attribute\Middleware(...)]`
   and call `MiddlewareCatalog::registerAttributed(YourMiddleware::class)`
   once at bootstrap. No factory needed — the class is resolved through the DI
   container, and its position is computed from the attribute instead of
   being passed explicitly. See [Attribute-based registration](#attribute-based-registration).

## API

```php
use Quiote\Middleware\MiddlewareCatalog;

MiddlewareCatalog::register(
    string   $fqcn,      // identity + label shown in the debug stack (use the class name)
    callable $factory,   // () => PSR-15 MiddlewareInterface  (lazy; called when the pipeline builds)
    ?string  $after  = null,   // insert immediately AFTER this middleware's FQCN
    ?string  $before = null,   // insert immediately BEFORE this middleware's FQCN
    int      $priority = 0,    // tie-break ordering among registered middleware (higher runs earlier)
);
```

- `$factory` returns a `Psr\Http\Server\MiddlewareInterface`. It is invoked once,
  when the pipeline is first built.
- The target of `before:`/`after:` may be a **framework** middleware (see anchors
  below) **or another registered** middleware (chaining is supported).
- If neither `before:` nor `after:` is given, the middleware is inserted **just
  before `SecurityMiddleware`** (a safe default: after routing/negotiation, before
  auth). If the named target isn't found, the same fallback applies.

## Where to register — at bootstrap, before `run()`

The pipeline is built lazily on the first request and cached for the worker's
lifetime, so **all registrations must happen before `QuioteKernel::run()`**.
Do it in a bootstrap class invoked from `index.php`:

```php
// src/App/Bootstrap/MiddlewareBootstrap.php
final class MiddlewareBootstrap
{
    public static function register(): void
    {
        MiddlewareCatalog::register(
            HealthzMiddleware::class,
            fn() => new HealthzMiddleware(),
            before: \Quiote\Middleware\SessionMiddleware::class,   // answer /healthz before touching the session
        );
        MiddlewareCatalog::register(
            JwtAuthMiddleware::class,
            fn() => new JwtAuthMiddleware(),
            after: \Quiote\Middleware\RoutingMiddleware::class,    // needs the matched route
        );
        MiddlewareCatalog::register(
            ApiAuthMiddleware::class,
            fn() => new ApiAuthMiddleware(),
            after: JwtAuthMiddleware::class,                      // chained: after another registered mw
        );
    }
}
```

```php
// index.php — before the kernel runs
App\Bootstrap\MiddlewareBootstrap::register();
Quiote\Runtime\QuioteKernel::create([...])->run();
```

## Built-in anchor points (execution order, outermost first)

Use any of these FQCNs as a `before:`/`after:` target:

```
ErrorHandlingMiddleware      (outermost — catches everything)
SessionMiddleware
TimingMiddleware             (optional — see enable/disable)
TraceMiddleware              (optional)
PayloadParsingMiddleware     (JSON + form body)
ContentNegotiationMiddleware
RoutingMiddleware            (route is known after this)
OutputTypeSyncMiddleware
CsrfInjectionMiddleware
CsrfValidationMiddleware
SecurityMiddleware           (authentication/authorization)
ValidationMiddleware
SlotMiddleware
DispatchMiddleware           (runs the action — effectively terminal)
AssetAggregationMiddleware
FormPopulationMiddleware
ExecutionTimeMiddleware      (optional)
```

Registered middleware are spliced into this stack at their requested positions
before the internal terminal sentinel.

## Ordering rules & caveats

- **Registration order matters for chains at equal priority.** Registered
  middleware are processed in registration order (stable sort by `priority`
  descending). So when middleware B is positioned `after: A` and A is itself
  registered, **register A before B** — otherwise A isn't in the stack yet when B
  looks for it, and B falls back to "before `SecurityMiddleware`". Use `priority`
  to make intent explicit if you don't want to rely on registration order.
- **Register once.** Registrations are process-global static state. Registering
  the same FQCN twice overwrites the earlier entry (keyed by FQCN).
- **Enable/disable.** Any middleware (framework or registered) can be toggled off
  via `MiddlewareCatalog::initialize([Fqcn::class => false])` (populated from
  `<middleware_config>`). A disabled registered middleware is skipped entirely.

## Attribute-based registration

Every framework middleware class carries a
`#[Quiote\Middleware\Attribute\Middleware(phase:, priority:, before:, after:, enabled:)]`
attribute, and the built-in order above is *derived* from these attributes at
pipeline-build time (`Quiote\Middleware\Compiler\MiddlewareAttributeScanner` +
`MiddlewareOrderResolver`) — it is no longer a hand-maintained sequence. An
application can add its own middleware the same way:

```php
use Quiote\Middleware\Attribute\Middleware;
use Quiote\Middleware\MiddlewareCatalog;

#[Middleware(phase: 'before_action', after: 'RoutingMiddleware', before: 'SecurityMiddleware')]
final class HealthzMiddleware implements \Psr\Http\Server\MiddlewareInterface
{
    // ...
}

// bootstrap, before run():
MiddlewareCatalog::registerAttributed(HealthzMiddleware::class);
```

- `phase` is the primary ordering key — one of, in order: `bootstrap`,
  `pre_routing`, `pre`, `routing`, `before_action`, `action`, `after_action`,
  `finalize`. Pick the phase that matches when your middleware needs to run
  relative to routing/auth/dispatch, not necessarily immediate adjacency to a
  specific class.
- `before`/`after` may reference a **short class name** (e.g.
  `'RoutingMiddleware'`) or a fully-qualified one. Unlike `register()`'s
  `before:`/`after:`, these are ordering *constraints* within the topological
  sort, not "insert immediately next to" — `priority` (higher runs earlier,
  default `0`) breaks ties within the same phase, then declaration order.
  A short name that matches more than one scanned class, or that matches
  nothing, is logged and ignored rather than failing the build; a genuine
  cycle in `before`/`after` throws `MiddlewareOrderException` when the
  pipeline builds.
- `enabled: false` disables the middleware by default. `<middleware_config>`
  (via `MiddlewareCatalog::initialize()`) always overrides it when the FQCN
  has an explicit entry there, in either direction.
- Attribute-registered middleware is resolved via the DI container
  (`Container::get()`), so it must be autowireable — unlike `register()`,
  there's no factory closure to reach for special construction.
- **`register()` wins over the attribute for the same FQCN.** If a class is
  both `registerAttributed()`-ed and `register()`-ed, the `register()` call's
  factory and before/after/priority are used and the attribute is ignored for
  placement purposes (a diagnostic is still logged). This lets you override an
  attribute-declared class's default position without editing the class.
  See `docs/MIDDLEWARE_ATTRIBUTE_REGISTRATION_PLAN.md` for the full design.

## Verifying position

`MiddlewarePipeline::debugStack()` returns the ordered list of labels for the
built pipeline — handy in a test to assert your middleware landed where you
expect (see `test/tests/unit/middleware/MiddlewareRegistrationTest.php`).
