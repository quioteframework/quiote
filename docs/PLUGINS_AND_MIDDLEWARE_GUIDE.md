# Plugins & Middleware — Developer Guide

Practical, copy-pasteable steps for the four most common tasks. For the full
design/status of each subsystem see `docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md`
and `docs/MIDDLEWARE.md`.

**The security model, in one sentence:** an attribute (`#[Plugin]` /
`#[Middleware]`) makes a class *eligible*; a config file (`plugins.*` /
`middleware.*`) is what *activates* it. Neither one alone does anything —
`composer require`-ing a package, by itself, can never turn a plugin or
middleware on.

---

## a) Use the `db-propulsion` plugin

`db-propulsion` adds a `propulsion` database driver alias backed by
[Propulsion](https://github.com/quioteframework/propulsion) (the maintained
Propel 1 fork). It contributes exactly one thing: a `DatabaseDriverRegistry`
alias — no config defaults, no services, no middleware.

1. **Install both packages** (the plugin package pulls in the ORM itself as
   a dependency, but call it out explicitly since the error message you'd
   get without it references installing it separately):
   ```bash
   composer require quioteframework/db-propulsion
   ```

2. **Enable the plugin** in `Config/plugins.php` (or `.xml`/`.yaml`/`.yml`):
   ```php
   <?php
   return [
       ['class' => \Quiote\Database\Adapter\Propulsion\PropulsionPlugin::class, 'enabled' => true],
   ];
   ```

3. **Reference the `propulsion` driver alias** in `Config/databases.xml`
   (or `.php`/`.yaml`), the same alias pattern as the Eloquent/Doctrine/Cycle
   adapters:
   ```xml
   <database name="default" class="propulsion">
       <parameter name="config">%core.config_dir%/propulsion-runtime-config.php</parameter>
       <!-- optional: -->
       <parameter name="datasource">default</parameter>
   </database>
   ```
   - `config` (**required**) — path to a PHP file that `return`s Propulsion's
     runtime config array.
   - `datasource` (optional) — defaults to the config's own
     `datasources.default` key if omitted.
   - `overrides` / `init_queries` / `enable_instance_pooling` (all optional)
     — see `Quiote\Database\Adapter\Propulsion\PropulsionDatabase` for exact
     shapes.

   Without step 2, `class="propulsion"` fails to resolve — core only ships
   the `pdo` alias by default.

---

## b) Use the whoops (developer exception) plugin

This is **not middleware** — it's an exception-*renderer* plugin that plugs
into the existing, always-on `ErrorHandlingMiddleware`. Two switches must
both be on for it to actually render anything; either one alone leaves you
on the safe, no-detail renderer.

1. **Install:**
   ```bash
   composer require quioteframework/whoops
   ```

2. **Enable the plugin** in `Config/plugins.php`:
   ```php
   <?php
   return [
       ['class' => \Quiote\Exception\Rendering\Whoops\WhoopsPlugin::class, 'enabled' => true],
   ];
   ```

3. **Turn on developer exceptions** — a separate, deliberate switch (default
   `false`, and unrelated to `core.debug`) in `settings.*`, typically only in
   a `dev`/`local` environment file:
   ```php
   'core.developer_exceptions' => true,
   ```

Both are required: the plugin registers *a* candidate renderer
(`ExceptionRendererRegistry::setDeveloperRenderer()`, set-if-absent — first
one registered wins), but `ErrorHandlingMiddleware` only reaches for it when
`core.developer_exceptions` is true; without that setting it always uses
`SafeRenderer` regardless of what's registered. Never enable
`core.developer_exceptions` in production — it renders full stack traces.

**Note:** `quiote new`'s scaffolded app does **not** wire this up
automatically, even though the generated `/boom` demo route's copy mentions
"the Whoops developer page." Both steps above are always manual.

---

## c) Write your own plugin

1. **Implement the interface** and mark it discoverable:
   ```php
   <?php
   namespace App\Plugin;

   use Quiote\Plugin\Attribute\Plugin;
   use Quiote\Plugin\PluginInterface;
   use Quiote\Plugin\PluginRegistrar;

   #[Plugin]
   final class HealthzPlugin implements PluginInterface
   {
       public function name(): string
       {
           return 'app/healthz';
       }

       public function register(PluginRegistrar $registrar): void
       {
           $registrar
               ->configDefault('healthz.path', '/healthz')
               ->attributedMiddleware(\App\Middleware\HealthzMiddleware::class);
       }
   }
   ```
   `#[Plugin]` is **mandatory** for any plugin activated via a class-string
   (a `plugins.*` file, or `PluginManager::add('App\Plugin\HealthzPlugin')`)
   — a class without it is silently refused (logged) even if it's correctly
   named somewhere. It's skipped only for `PluginManager::add(new
   HealthzPlugin())` — passing an already-built instance is itself the trust
   boundary, since your own code named the class directly.

2. **Pick contribution methods on `PluginRegistrar`** inside `register()` —
   all fluent, mix and match as needed:

   | Method | Effect |
   |---|---|
   | `configDefault(key, value)` | Set-if-absent config default |
   | `service(id, concrete, scope, ...aliases)` | Register-if-absent DI binding |
   | `middleware(fqcn, factory, after:, before:, priority:)` | `MiddlewareCatalog::register()` |
   | `attributedMiddleware(fqcn, factory?)` | `MiddlewareCatalog::registerAttributed()` |
   | `listen(eventClass, listener, priority)` | `Events::listen()` |
   | `moduleDirectory(dir)` | Extra dir for the `#[Route]` scanner |
   | `command(fqcn)` | Console command contribution |
   | `databaseDriver(alias, adapterClass)` | `DatabaseDriverRegistry` alias |
   | `httpClient(name, configurator)` | Named `HttpClientFactory` client |
   | `developerExceptionRenderer(factory)` | Set-if-absent developer renderer |

   Config defaults and services are set-if-absent: app `settings.*` (loaded
   before plugins) always wins, and among plugins the first to contribute a
   given key wins.

3. **Activate it** in `Config/plugins.php`:
   ```php
   <?php
   return [
       ['class' => \App\Plugin\HealthzPlugin::class, 'enabled' => true],
   ];
   ```
   or programmatically before `Quiote::bootstrap()` runs:
   ```php
   \Quiote\Plugin\PluginManager::add(new \App\Plugin\HealthzPlugin());
   ```

A module can also ship its own `Modules/<Name>/Config/plugins.php` — it's
discovered automatically (no app wiring needed); app-declared plugins are
compiled first, so the app always wins on a same-class conflict.

---

## d) Write your own middleware

1. **Implement PSR-15** and describe its position with `#[Middleware]`
   (the attribute is optional — see step 2 for the config-only alternative):
   ```php
   <?php
   namespace App\Middleware;

   use Psr\Http\Message\ResponseInterface;
   use Psr\Http\Message\ServerRequestInterface;
   use Psr\Http\Server\MiddlewareInterface;
   use Psr\Http\Server\RequestHandlerInterface;
   use Quiote\Middleware\Attribute\Middleware;

   #[Middleware(phase: 'pre_routing', before: 'SessionMiddleware')]
   final class HealthzMiddleware implements MiddlewareInterface
   {
       public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
       {
           if ($request->getUri()->getPath() === '/healthz') {
               return new \Nyholm\Psr7\Response(200, [], 'ok');
           }
           return $handler->handle($request);
       }
   }
   ```
   `phase` is the primary ordering key, one of (in order): `bootstrap`,
   `pre_routing`, `pre`, `routing`, `before_action`, `action`, `after_action`,
   `finalize`. `before`/`after` accept a short class name or FQCN;
   `priority` (higher runs earlier) breaks ties within a phase.

2. **Register it** — the preferred way is `Config/middleware.php` (or
   `.xml`/`.yaml`/`.yml`):
   ```php
   <?php
   return [
       ['class' => \App\Middleware\HealthzMiddleware::class],
   ];
   ```
   A class with *no* `#[Middleware]` attribute at all can be fully specified
   this way too — any field left unset in the config entry falls back to
   the attribute's value (or the framework default: `phase: 'pre'`,
   `priority: 0`) rather than requiring the attribute to exist:
   ```php
   ['class' => \App\Middleware\HealthzMiddleware::class, 'phase' => 'pre_routing', 'before' => 'SessionMiddleware']
   ```
   Same as plugins, a module's own `Modules/<Name>/Config/middleware.*` is
   discovered automatically, no app wiring needed.

   Two code-based alternatives also exist (see `docs/MIDDLEWARE.md` for full
   detail): `MiddlewareCatalog::registerAttributed($fqcn)` (attribute
   required, DI-resolved) and `MiddlewareCatalog::register($fqcn, $factory,
   after:, before:, priority:)` (positional, explicit factory — the escape
   hatch for middleware that needs constructor args the container can't
   autowire).

3. **Never touch a shipped framework middleware without both switches.**
   Naming one of Quiote's own classes (`ErrorHandlingMiddleware`,
   `SessionMiddleware`, `RoutingMiddleware`, `SecurityMiddleware`, etc. — see
   `MiddlewarePipeline::coreMiddlewareClasses()`) to change its placement or
   `enabled` state requires **both**, on purpose:
   ```xml
   <use class="Quiote\Middleware\TimingMiddleware" enabled="false" override-framework="true" />
   ```
   ```php
   'core.middleware.allow_framework_overrides' => true,
   ```
   Either alone throws a `ConfigurationException` at config-load time (not
   deferred to the first request) — a config file, especially one dropped in
   by a module, should never be able to silently disable error handling or
   CSRF just by naming the class.
