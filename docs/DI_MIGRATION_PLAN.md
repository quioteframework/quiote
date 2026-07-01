# Dependency Injection Migration — replace `factories.xml` with a real container

**Status:** Plan / design
**Goal:** Introduce a scope-aware DI container as the framework's service backbone,
retire the bespoke `factories.xml` + `QuioteFactoryConfigHandler` codegen mechanism,
and give application "manager" classes constructor-injected dependencies instead of
reaching back through `$this->getContext()->getModel(...)`.

**Strangler, not big-bang.** Unlike the logging rewrite, this cannot be a clean
sweep: `QuioteContext` and `getModel()` are load-bearing (135+ `getContext()` call
sites across 74 framework files; ~98 manager classes fetched via `getModel()` in the
jakamo app; zero of them currently use a container). The plan is phased so each step
is independently shippable and reversible, and the **highest-value work (manager DI)
does not require removing `factories.xml` at all**.

**Context this plan assumes:** the jakamo monolith is being strangled and retired over
a multi-year horizon. The objective is life-extension and testability, not
architectural purity. Phases are ordered by ROI, and the plan explicitly flags which
phases can be deferred indefinitely.

---

## 1. Goals / non-goals

**Goals**
- A scope-aware PSR-11 container (`singleton` / `transient` / `request`-scoped) as the
  service backbone, safe under FrankenPHP long-running workers.
- Introduce a **first-class Service concept**, distinct from Model: services are
  resolved via `getService()` and/or constructor injection, and do **not** extend
  `QuioteModel`. This un-conflates the two things "model" has always meant in Quiote
  (see §2.5): singleton service objects vs. transient data objects.
- Services declare typed constructor dependencies and become unit-testable, replacing
  the runtime service-locator pattern (`$this->getContext()->getModel('Other')`).
- Remove `factories.xml`, `QuioteFactoryConfigHandler`, the factories RNG/XSD, and the
  code-generation path — replaced by a PHP service-definitions provider plus a
  lifecycle orchestrator.
- Preserve 100% backward compatibility of the public surface (`getModel()`,
  `getFactoryInfo()`, `createInstanceFor()`, and every `getX()` getter) throughout.

**Non-goals (this pass)**
- Adopting a third-party container (Symfony DI / PHP-DI / League). See §3 — we extend
  the existing `Quiote\DI\Container`. Third-party adoption is an explicit escape hatch,
  not the plan.
- Compiled/cached container (Symfony-style build-time compilation). Revisit only if
  FrankenPHP cold-start profiling demands it.
- **Touching `getModel()`.** It stays exactly as-is and keeps serving the legitimate
  transient-DTO case (Product-style data objects). Services move to `getService()` /
  injection; models are left alone.
- **Renaming / migrating the ~98 jakamo managers.** That is app-side churn
  (`UserManagerModel` → `UserManagerService`, reinject, rewrite call sites) and gets its
  own plan (§5, Phase 5) — it does not gate the framework work here.
- Reviving multi-context profiles (`console`, `soap`). Out of scope.

---

## 2. Current state (what we are replacing)

### 2.1 `factories.xml` is registration, not a factory pattern
It is Quiote's `services.yaml` / `ConfigureServices` equivalent: a manifest naming which
class fills each core role, plus config parameters. The jakamo app
(`src/Jakamo/Config/factories.xml`) declares exactly the **9 standard roles** —
`controller`, `database_manager`, `request`, `response`, `routing`, `storage`,
`translation_manager`, `user`, `validation_manager` — with 4 custom classes
(`JakamoRouting`, `QuioteStorageAdapter`, `JakamoRbacUser`, `JakamoTranslationManager`)
and `development.*` / `testing.*` / `production.*` overlays that tweak
storage/response/validation params. **No custom roles, no module-level factories.**

### 2.2 The mechanism (`QuioteFactoryConfigHandler.php:56-221`)
The XML is compiled to PHP that runs inside `QuioteContext::initialize()`
(`QuioteContext.php:684-724`, via `include`/APCu-`eval` of the config cache). The
generated code encodes a **fixed lifecycle**, which is the real value and the hard part
to preserve:

- **Instantiation + `startup()` order** (`QuioteFactoryConfigHandler.php:66-122`):
  `database_manager` (+`startup`) → `translation_manager`/`routing`/`request`/`controller`
  → `storage` (+`startup`) → `user`, then `startup()` of translation/user/routing/controller.
  db-before-storage-before-user matters: `QuioteStorageAdapter::initialize()` pulls the PDO
  from the db manager; `JakamoRbacUser::initialize()` fetches `UserManager`.
- **`shutdownSequence`** built in reverse (`array_unshift`).
- **Two flavors**: eager `var`-based instances (controller, request, routing, storage,
  user, db, translation) vs. lazy `factory_info` entries (`response`,
  `validation_manager`) resolved on demand via `createInstanceFor()` (`QuioteContext.php:278`).
- **`*FactoryInfo`** capture (`QuioteContext.php` `requestFactoryInfo` etc.) — worker-mode
  `reset()` (`QuioteContext.php:390`) nulls request/user/storage/routing and rebuilds them
  lazily from this metadata. `initialize()` hard-fails without it
  (invariant check `QuioteContext.php:729-748`).
- **FrankenPHP pre-request user-deferral hack** (`QuioteContext.php:790-812`).

### 2.3 The two-phase `initialize()` pattern
No constructor injection. Objects are built bare (`new $class()`) then handed the context
via `initialize(QuioteContext $context, array $parameters)` to go fishing for what they
need. This is the service-locator smell we want to remove for new code.

### 2.4 Managers today (jakamo survey)
~98 distinct manager classes fetched via `getModel()`. **None implement
`QuioteISingletonModel`** — so each `getModel('FooManager')` builds a *fresh, transient*
instance. Dependencies are fetched at runtime, often lazily inside methods
(`EngineeringChangeManagerModel` calls `$this->getContext()->getModel('CurrencyManager')`
deep inside `getEngineeringChanges()`), not in a constructor. This transient default and
the lazy/conditional lookups are critical constraints for Phase 3.

### 2.5 What a "model" actually is (verified against pristine Quiote 1.x)
Inspection of the untouched upstream framework (`../quiote-legacy`) settles the design
question definitively: **classic Quiote has zero model→view binding.** Evidence:
- `QuioteView` has no `getModel`/`setModel`; `QuioteRenderer::render()` takes
  `$attributes`/`$slots`/`$moreAssigns`, never a model. Templates only ever see view
  attributes.
- `QuioteAction::execute()` returns a *view name*, never a model. Data reaches the view
  solely via `setAttribute()`. Canonical sample flow:
  `$x = getModel('ProductFinder')->retrieveAll(); $this->setAttribute('products', $x); return 'Success';`
  — the model object never crosses into the template.
- Outside `src/model/`, nothing in the dispatch/view/render path references `QuioteModel`.
  The only touch-point is `QuioteContext::getModel()`.
- The base-class docblock defines a model as *"a convention for separating business
  logic... a globally accessible API for other modules"* — i.e. a **service**.

So "model" has always meant **two unrelated things**, both present in the sample app:
1. **Singleton finder/repository** (`ProductFinderModel implements QuioteISingletonModel`)
   — a **service**. The ~98 jakamo managers are this, mislabeled.
2. **Transient data object** (`ProductModel`, built from a row via
   `getModel('Product', null, [$row])`) — a **DTO**, optionally "fat" with business
   logic. This is the only thing that deserves the name "model," and even it is never
   view-bound.

**Consequence for this plan:** extracting managers into a `Service` layer is not
inventing a new concept — it is un-conflating what Quiote mashed together. `getModel()`
keeps the DTO half; a new `Service` layer takes the service half.

### 2.6 The container we already have
`src/DI/Container.php` — a PSR-11 container with closure/class/instance definitions,
aliases, and reflection autowiring — exists but is **orphaned** (only referenced by
`test/tests/unit/middleware/ContainerTest.php`). It is the starting point, not
greenfield.

---

## 3. Container decision: extend `Quiote\DI\Container`

`Container.php` does **not** currently do what we need. Gaps:

| Gap | Why it matters here |
| --- | --- |
| **Singleton-only** (`$resolved` caches forever) | Managers are transient today; request/user/storage must be request-scoped or they leak across FrankenPHP requests. |
| **No `reset()`** | Worker mode must drop request-scoped instances between requests, in lockstep with `QuioteContext::reset()`. |
| **No scalar/param binding** | `autoWire()` only fills class-typed params or defaults; the `<ae:parameter>` values (`cookie_name`, session `table`, `mode=strict`) have nowhere to go. |
| **No cycle detection** | A dependency cycle is a stack overflow, not a `ContainerException`. |
| **Greedy `has()`** | Returns `true` for any `class_exists()`, so autowiring tries to build value objects / unregistered types. |

**The decisive point:** the hard part of `factories.xml` is the ordered
`initialize → startup → shutdown` lifecycle, and **no off-the-shelf container models
that** — Symfony/PHP-DI/League all give construction + autowiring, not a four-phase
ordered lifecycle with worker-mode `*FactoryInfo` recreation. That orchestrator must be
hand-written regardless of the container underneath.

**Decision: extend `Container.php`** (add scopes, param binding, cycle guard, `reset()`,
honest `has()`). It is ~130 lines we control, matches Quiote's existing "compile config to
PHP" philosophy, and avoids adding a dependency + learning curve to a codebase being
retired.

**Escape hatches** (only if a hard constraint forces it):
- **League/Container** — closest drop-in; inflectors call `initialize()` on every resolved
  service implementing an interface, and `add`/`addShared` give transient/singleton.
- **PHP-DI** — if FrankenPHP cold-start perf pushes toward a compiled container.
- **Symfony DI** — most powerful, but overkill for a monolith being retired.

---

## 4. Invariants any implementation must preserve

1. Instantiation + `startup()` order (db → storage → user; §2.2) and reverse-order shutdown.
2. Eager `var`-based instances vs. lazy `factory_info` (`response`, `validation_manager`).
3. `*FactoryInfo` capture + the `initialize()` invariant check (`QuioteContext.php:729-748`).
4. Worker-mode `reset()` + lazy recreation of request/user/storage/routing.
5. FrankenPHP pre-request user-deferral hack (`QuioteContext.php:790-812`).
6. Environment overlays (base + `development.*` / `testing.*` / `production.*`).
7. BC surface: `getModel()`, `getFactoryInfo()`, `createInstanceFor()`, all `getX()` getters.
8. `must_implement` validation (currently compile-time in the handler) → runtime `instanceof`.

---

## 5. Phased plan

### Phase 0 — Harden `Container.php` (prereq; zero production impact)
Add:
- **Scopes**: `singleton` (default), `transient` (never cached), `request` (cached, cleared
  on `reset()`). Registration API carries the scope.
- **`reset()`**: drop request-scoped + per-request resolved entries.
- **Parameter binding**: a definition shape carrying constructor/`initialize` scalar params
  so `<ae:parameter>` values are injectable.
- **Cycle guard**: a resolving-stack set → throw `ContainerException` on re-entry.
- **Honest `has()`**: PSR `has()` reflects registered entries only; split out an internal
  `canAutowire()` for the autowiring path.
- **Attribute support** (attributes are inert metadata — the container is what reflects on
  them and acts):
  - **`#[Required]`** (`Symfony\Contracts\Service\Attribute\Required`, already a dep) —
    after `make()`/autowire builds an object, scan for methods marked `#[Required]` and call
    each with **container-autowired** args. This is optional setter/method injection for
    cross-cutting deps (e.g. a logger) that shouldn't clutter every constructor. Same
    mechanism Symfony uses for `AbstractController::setContainer()`.
  - **Guard: reject `#[Required]` on `initialize()`.** `initialize()` is a framework
    lifecycle hook invoked by the *executor* with a per-execution context the container does
    not own; letting the container also call it (and try to autowire `ActionInitContext`)
    is a category error. When scanning `#[Required]` methods, if one is named `initialize`
    (or type-hints `ActionInitContext`/`ViewInitContext`), **throw a `ContainerException`**
    with a message pointing to constructor injection or an autowired setter instead.
  - **`#[Service(scope: ...)]`** (custom, class-level) — discovery marker + scope
    declaration; the alternative to a marker interface (see Phase 3). Follows the existing
    `#[\Quiote\Middleware\Attribute\QuioteMiddleware]` precedent.
  - **`#[Inject('id')]` / `#[Autowire(...)]`** (custom, parameter-level) — override
    autowiring-by-type for a scalar/config value or to pick among multiple implementations.

Full unit tests per capability (including the `initialize()`-rejection guard). Container
remains unused in production after this phase.

### Phase 1 — Put the container *behind* `QuioteContext` (additive, reversible)
- Lazy `QuioteContext::getContainer()`.
- After `factories.xml` runs, register the already-created core objects into the container
  as instances, keyed by **role name and class/interface** (so both `get('user')` and
  `get(JakamoRbacUser::class)` resolve).
- Wire container `reset()` into `QuioteContext::reset()`.
- Nothing removed yet — proves coexistence + worker reset before the container *owns*
  creation.

### Phase 2 — Replace XML + codegen with PHP definitions + a lifecycle orchestrator
*(Optional / deferrable — see §7.)*
- App ships `Config/services.php` returning ordered core-service definitions
  `{role, class, params, scope, lifecycle:[initialize,startup,shutdown], order}` — a direct
  translation of `factories.xml` (base + env overlays merged in PHP).
- Framework gets a `CoreServiceBootstrapper` that consumes the definitions and reproduces
  the generated code's behavior: instantiate in order → `initialize($context, $params)` →
  `startup()` → build `shutdownSequence` → populate `*FactoryInfo`.
- `QuioteContext::initialize()` calls the bootstrapper instead of `include`-ing the compiled
  `factories.xml`. Keep `response`/`validation_manager` lazy; preserve the user-deferral
  hack; enforce `must_implement` as a runtime `instanceof` assertion.
- Then delete `factories.xml`, `QuioteFactoryConfigHandler`, factories RNG/XSD, and the
  `factories` entry in `config_handlers.xml`. `getFactoryInfo`/`createInstanceFor`/getters
  become thin façades over the container.

### Phase 3 — Introduce the Service layer (framework side; the actual value)
This is the framework capability that makes services first-class. It does **not** touch
`getModel()` and does **not** migrate any jakamo class (that is Phase 5).

- **`QuioteContext::getService(string $id)`** — thin wrapper over `$container->get($id)`.
  The locator escape hatch for legacy call sites and lazy/conditional access (the
  `IServiceProvider`-injection equivalent from .NET). Preferred path for new code is
  constructor injection (`$this->orderService->...`); both resolve through the same
  container.
- **Service typing — keep it thin, don't mandate a heavy base:**
  - A **`#[Service(scope: ...)]`** class attribute (preferred) and/or a marker interface
    `Quiote\Service\QuioteServiceInterface` so the container / `getService()` can discriminate
    services from arbitrary classes. The attribute is cleaner — no forced base, and it
    declares scope in one place.
  - An **optional, transitional** `Quiote\Service\QuioteService` base exposing `getContext()`
    only — scaffolding so a half-migrated service can still reach
    `$this->getContext()->getModel('Other')` while its collaborators are converted. It is
    a crutch to shed, **not** a permanent parent, and it does **not** extend `QuioteModel`.
  - Cross-cutting optional deps (e.g. a logger) can arrive via an **`#[Required]` autowired
    setter** instead of the constructor.
  - End state: a service is a POPO with constructor-injected deps and no base class.
- **Scope default:** services are transient today (as models, none are
  `QuioteISingletonModel`). Register them **transient or request-scoped by default** —
  promoting a stateful service to a process singleton under FrankenPHP is a latent
  cross-request bug. Opt into `singleton` only for verified-stateless services.
- **Lazy/conditional deps:** where a service needs another service deep inside a method
  (not at construction — cf. `EngineeringChangeManagerModel`), inject a **factory closure
  or the container/`getService()`** rather than forcing eager construction.

`getModel()` is untouched throughout this phase.

### Phase 3b — Constructor injection for actions & views (the action-side enabler)
**Current state:** neither is DI-capable. Both are instantiated parameterless at a single
choke point each and then handed context via the two-phase `initialize()`:
- Actions: `QuioteController::createActionInstance()` → `new $class()` (`:315,327`), then
  caller runs `$action->initialize($actionInitContext)` (`ActionExecutor.php:181`).
- Views: `createViewInstance()` → `new $class()`, then `ViewFactory` runs
  `$view->initialize($viewInitContext)` (`ViewFactory.php:48`).

All action-dispatch routes funnel through `createActionInstance()` — `ActionExecutor`,
`SlotDispatcher` sub-actions (`:148,595`), and the legacy `QuioteExecutionContainer`
(`:891`) — so one change covers them all.

**What to add:**
- **`Container::make($class)`** — a non-caching autowire that returns a *fresh* instance
  every call (actions/views are per-execution, must never be memoized like `get()` does).
  This is the transient path from Phase 0, exposed as a public entry point.
- **Route both choke points through `make()`** instead of `new $class()`.
- **Keep `initialize($initContext)` after construction, unchanged.** The two-phase pattern
  stays and *composes* with DI: **constructor = injected services; `initialize()` = the
  framework's request/execution context.**
- **Per-class reflection cache** in the container (immutable class metadata, safe
  process-global under FrankenPHP) so we don't reflect every action constructor per request.

**Backward compatible:** actions/views with no constructor (nearly all today) hit
`autoWire()`'s "no constructor → `new $class()`" branch and behave identically — zero
migration for untouched classes.

**Enables the target refactor:** add `__construct(private OrderService $orders)` to an
action and replace `$this->getContext()->getModel('Order')->x()` with `$this->orders->x()`.

**Recommended convention (two lanes):**
- **Constructor = app services** (autowired). Each action's ctor lists only what *it* needs.
- **`initialize($initContext)` = framework context** (request/execution). Stays a **plain
  method the executor calls** (`ActionExecutor.php:181`) — **not** `#[Required]`. It runs
  **unconditionally** on every instance regardless of what constructor a subclass declares.
  Move context into the ctor and correct wiring becomes contingent on every subclass
  threading `parent::__construct($ctx)` — an obligation that surfaces either as an
  `ArgumentCountError` (wrong args) or, if the child ctor never calls parent, as a silently
  unset context. `initialize()` avoids the whole class of mistake and keeps Phase 3b purely
  additive (no existing action changes). Precedent: **Symfony** delivers the container to
  `AbstractController` via `#[Required] setContainer()` (method injection); **ASP.NET Core**
  keeps `HttpContext` off the ctor. Difference vs. those: our `initialize()` takes the
  per-execution context (not a container-owned service), so it is *executor*-invoked, not
  container-invoked — hence **`#[Required]` on `initialize()` is rejected by the container**
  (Phase 0 guard). This is a convention/ergonomics preference, not a technical limit.
- Phase 0's honest `has()` makes a mistyped/unregistered service dep a loud
  `ContainerException`, not a silent null.

**On injecting the init-context itself (possible, but not the default):**
- It *can* be constructor-injected via an **explicit construction-time override** —
  `make($class, [ActionInitContext::class => $lwCtx])` (the .NET
  `ActivatorUtilities.CreateInstance(provider, extraArgs)` pattern). For actions this would
  even eliminate `initialize()`, since that method is pure assignment. Requires building the
  context *before* `createActionInstance()` (currently built after, `ActionExecutor.php:172`).
- **Do NOT** inject it via an *ambient scoped binding* ("the current init-context" as a
  scoped service): `SlotDispatcher` runs sub-actions re-entrantly (`:148,333`), each with its
  own context, so an ambient singleton would need a push/pop stack or a sub-action clobbers
  the parent's context. The explicit-override mechanism sidesteps this entirely.

**Timing constraint (for services, regardless of the above):** constructor injection runs at
creation, before `initialize()`; an injected *service* cannot read the current request *from
the action*. Services needing per-request state get it via their own request-scoped
registration, and the request scope must be open before action dispatch (it is, in the
middleware pipeline).

### Phase 4 — Cleanup
Remove dead factories config-cache plumbing; retire `QuioteFactoryConfigHandlerTest`; update
`ConfigHandlersTest` and `QuioteContextTest::testGetModel`.

### Phase 5 — (SEPARATE FUTURE PLAN) jakamo manager → service migration
App-side churn, deferred to its own plan document; listed here only for continuity. Rough
shape: rename the ~98 `*ManagerModel` classes to `*Service` (e.g. `OrderManagerModel` →
`OrderService`), switch them from `extends QuioteModel` to the Service layer, register them
in the app's service definitions, inject them into actions/other services, and rewrite call
sites from `$this->getContext()->getModel('OrderManager')` to injected `$this->orderService`
(or `getService()` where injection isn't yet practical). Genuine data objects (Product-style
DTOs) stay as models on `getModel()`. This is large, mechanical, and independently
schedulable — do it module by module, not in one pass.

---

## 6. Jakamo-specific work

- Translate `src/Jakamo/Config/factories.xml` → `Config/services.php` (4 custom classes + 3
  env overlays). Small and mechanical.
- **The 4 custom classes need no changes in Phases 0–2**: the orchestrator calls their
  existing `initialize()/startup()/shutdown()` exactly as the generated code does now, in
  the same order — so `QuioteStorageAdapter` still gets its PDO and `JakamoRbacUser` still
  gets `UserManager`.
- `JakamoTranslationManager` has a real constructor and no `initialize()`; the orchestrator
  must tolerate definitions with a partial/absent lifecycle.

---

## 7. ROI note — what to actually do first

The core 9 factories are stable and `factories.xml` is not hurting anyone. **Phase 2/4
(deleting `factories.xml`) is the lowest-value, highest-risk slice** — it rewrites the
load-bearing worker-mode lifecycle to end up with the same 9 objects.

The pain we set out to fix — managers reaching through `$this->context->getModel('Other')`,
being untestable — is unlocked by the framework **Service layer** in Phases 0, 1, 3, which
do **not** require removing `factories.xml` and do **not** touch `getModel()`. Recommended
order of execution:

1. **Phase 0** (hardened container + tests) — pure upside, no risk.
2. **Phase 1** (container behind context) — enables everything else.
3. **Phase 3** (Service layer: `getService()` + marker/base + autowiring) — the real
   framework value; ships the capability without migrating any app class.
4. **Phase 3b** (constructor injection for actions & views) — the action-side enabler;
   `make()` + two choke points. Without this, injected services can't reach actions.
5. **Phase 2 / 4** (remove `factories.xml`) — **defer until it genuinely blocks something.**
6. **Phase 5** (jakamo manager → service rename) — separate future plan; schedule after the
   Service layer + action injection exist and are proven, module by module.

---

## 8. Risks / gotchas

- **Scope discipline under FrankenPHP.** The singleton-vs-request split must match what
  `QuioteContext::reset()` already tears down. A request-scoped service accidentally
  registered as a process singleton leaks state across requests. This is the single biggest
  risk.
- **Service transient-ness.** Do not silently promote services to process singletons
  (Phase 3) — they are transient today.
- **Lazy lookups can't all become constructor injection.** Conditional/deep service
  lookups need factory-closure or `getService()`/container injection, not eager wiring.
- **Ordering is load-bearing.** Encode core-service order explicitly in the definitions
  list; never rely on autowiring resolution order.
- **Cycle risk grows** as services gain constructor deps — Phase 0's cycle guard is a
  prerequisite for Phase 3, not optional.
- **Keep the `QuioteService` base a crutch, not a habit.** If new services routinely extend
  it to get `getContext()` instead of injecting deps, the migration has recreated the
  locator under a new name. Watch for this in review.
- **APCu/file config cache** for `factories.xml` disappears in Phase 2; a PHP definitions
  file is already opcache-friendly, so this is a simplification, but the config-cache
  invalidation path must be cleanly removed.
