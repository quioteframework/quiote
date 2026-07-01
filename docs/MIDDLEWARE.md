# Injecting custom middleware into the pipeline

Quiote builds a single PSR-15 middleware pipeline
(`Quiote\Middleware\MiddlewarePipeline`) once per worker and reuses it across
requests. Applications inject their own middleware at a **predefined position**
via `Quiote\Middleware\MiddlewareCatalog::register()`.

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

## Note: the `#[QuioteMiddleware]` attribute is NOT auto-registration

Framework middleware classes carry a `#[QuioteMiddleware(phase:, after:, before:)]`
attribute, but it is **descriptive only** — the pipeline builder does not scan
it. The built-in order is hard-coded in `MiddlewarePipeline::doBuild()`, and the
**only** way to inject application middleware is `MiddlewareCatalog::register()`.

## Verifying position

`MiddlewarePipeline::debugStack()` returns the ordered list of labels for the
built pipeline — handy in a test to assert your middleware landed where you
expect (see `test/tests/unit/middleware/MiddlewareRegistrationTest.php`).
