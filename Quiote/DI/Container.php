<?php

namespace Quiote\DI;

use Quiote\DI\Attribute\Autowire;
use Quiote\DI\Attribute\Inject;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Symfony\Contracts\Service\Attribute\Required;

class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface {}
class ContainerException extends \RuntimeException implements ContainerExceptionInterface {}

/**
 * Small scope-aware DI container: supports definitions as closures, class names, or instances.
 */
class Container implements ContainerInterface
{
    public const SCOPE_SINGLETON = 'singleton';
    public const SCOPE_TRANSIENT = 'transient';
    public const SCOPE_REQUEST = 'request';

    /**
     * Per-execution context types that #[Required] methods must never type-hint — they
     * belong to the executor, not the container (see guardRequiredMethod()).
     */
    private const array FORBIDDEN_REQUIRED_CONTEXT_TYPES = [
        \Quiote\Execution\ActionInitContext::class,
        \Quiote\Execution\ViewInitContext::class,
    ];

    /** @var array<string, array{concrete: mixed, scope: string, params: array<string, mixed>}> */
    private array $definitions = [];
    /** @var array<string, string> */
    private array $aliases = [];
    /** @var array<string, mixed> */
    private array $singletonResolved = [];
    /** @var array<string, mixed> */
    private array $requestResolved = [];
    /** @var array<string, bool> */
    private array $resolvingStack = [];

    /**
     * Per-class reflection cache. Class metadata (constructor params, attributes) is
     * immutable for the process lifetime, so this is safe to keep across requests under
     * a FrankenPHP worker — it just saves re-reflecting the same action/view/service
     * constructor on every request.
     * @var array<string, \ReflectionClass<object>>
     */
    private array $reflectionCache = [];

    /**
     * Per-class instantiation plan (constructor params + #[Required] methods),
     * computed once per process. See classPlan(). Immutable class metadata, so
     * FrankenPHP-worker safe like reflectionCache.
     * @var array<string, array{ctorParams: \ReflectionParameter[]|null, required: array<array{method: \ReflectionMethod, params: \ReflectionParameter[]}>}>
     */
    private array $planCache = [];

    /**
     * @param array<string, mixed> $params
     */
    public function set(string $id, mixed $concrete, string $scope = self::SCOPE_SINGLETON, array $params = []): void
    {
        $this->definitions[$id] = ['concrete' => $concrete, 'scope' => $scope, 'params' => $params];
        unset($this->singletonResolved[$id], $this->requestResolved[$id]);
    }

    /**
     * Forget a binding, and any instance already resolved from it.
     *
     * The counterpart to {@see set()}, and needed because binding null is not the same thing: an id
     * naming a class promises an instance of it, so `set($id, null)` is refused on the way out rather
     * than quietly answering null. "There is no session manager configured" has to be the *absence*
     * of a binding, which is what {@see tryGet()} answers null for.
     *
     * Mostly a test concern -- `Context::setSessionManager(null)` used to be how a suite dropped one
     * -- but it is the honest primitive for it either way.
     *
     * @since      4.0.0
     */
    public function unset(string $id): void
    {
        unset($this->definitions[$id], $this->singletonResolved[$id], $this->requestResolved[$id], $this->aliases[$id]);
    }

    public function alias(string $abstract, string $concrete): void
    {
        $this->aliases[$abstract] = $concrete;
    }

    public function setFactory(string $id, callable $factory, string $scope = self::SCOPE_SINGLETON): void
    {
        $this->set($id, $factory, $scope);
    }

    /**
     * Resolve a service.
     *
     * The conditional return type is what makes the container usable without a cast at every call
     * site: asked for a class name, this answers that class, and asked for a role string
     * (`'controller'`, `'user'`) it answers `mixed`, because nothing statically knows what a role is
     * bound to. That distinction matters now that `Context`'s typed accessors are gone -- code that
     * used to write `$context->getSlotDispatcher()` and get a `SlotDispatcher` writes
     * `$container->get(SlotDispatcher::class)` and still gets one.
     *
     * A binding whose value does not match the requested class is refused here, which is what makes
     * that conditional type a promise rather than a hope. `Context` used to run this check inside four
     * typed accessors; with the accessors gone it belongs to the one place every resolution passes
     * through, and it means a consumer that resolves a class name never has to defend against getting
     * something else.
     *
     * @template T of object
     * @param      string $id A class name, an interface name, or a role alias.
     * @phpstan-param class-string<T>|string $id
     * @return     mixed The resolved service.
     * @phpstan-return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        $lookupId = $this->aliases[$id] ?? $id;

        if (array_key_exists($lookupId, $this->singletonResolved)) {
            return $this->assertResolvedType($id, $this->singletonResolved[$lookupId]);
        }
        if (array_key_exists($lookupId, $this->requestResolved)) {
            return $this->assertResolvedType($id, $this->requestResolved[$lookupId]);
        }

        if (isset($this->resolvingStack[$lookupId])) {
            $path = implode(' -> ', [...array_keys($this->resolvingStack), $lookupId]);
            throw new ContainerException("Circular dependency detected while resolving '$lookupId': $path");
        }

        $this->resolvingStack[$lookupId] = true;
        try {
            [$obj, $scope] = $this->build($lookupId, $id);
        } finally {
            unset($this->resolvingStack[$lookupId]);
        }

        switch ($scope) {
            case self::SCOPE_TRANSIENT:
                break;
            case self::SCOPE_REQUEST:
                $this->requestResolved[$lookupId] = $obj;
                break;
            default:
                $this->singletonResolved[$lookupId] = $obj;
        }

        return $this->assertResolvedType($id, $obj);
    }

    /**
     * Hold {@see get()}'s side of the bargain: an id that names a class or interface resolves to one.
     *
     * Only checked when the id *is* a type name. A role alias (`'controller'`, `'user'`) promises
     * nothing statically, so nothing is asserted for it -- the caller of a role lookup already gets
     * `mixed` and has to decide for itself.
     *
     * @param      string $id The id as asked for, not the alias target: the message should name what
     *                    the caller wrote.
     * @since      4.0.0
     */
    private function assertResolvedType(string $id, mixed $resolved): mixed
    {
        if (!class_exists($id) && !interface_exists($id)) {
            return $resolved;
        }

        if ($resolved instanceof $id) {
            return $resolved;
        }

        throw new ContainerException(sprintf(
            'The container resolves "%s" to %s, which is not a %s. Check what was bound for it.',
            $id,
            get_debug_type($resolved),
            $id,
        ));
    }

    /**
     * Resolve $id, or null when it cannot be resolved.
     *
     * get() throws for an unregistered, non-autowireable service, which makes
     * the natural-looking
     *
     *     $client = $container->get(ClientInterface::class);
     *     if (!$client instanceof ClientInterface) { ... }
     *
     * a trap: the guard never runs, because get() has already thrown a
     * ContainerException with a message about autowiring rather than about the
     * thing the caller actually needs. Optional dependencies -- "use the app's
     * PSR-18 client if it bound one" -- should ask with this instead, and say
     * something useful when the answer is no.
     *
     * Carries {@see get()}'s conditional return type, so an optional dependency asked for by class
     * name is typed too -- `?SessionManager` rather than `mixed`.
     *
     * @template T of object
     * @param      string $id A class name, an interface name, or a role alias.
     * @phpstan-param class-string<T>|string $id
     * @return     mixed The resolved service, or null.
     * @phpstan-return ($id is class-string<T> ? T|null : mixed)
     * @since      3.0.0
     */
    public function tryGet(string $id): mixed
    {
        // has(), not a try/catch around get(): an unregistered *interface* cannot be autowired and so
        // came back null, but an unregistered concrete class with no required constructor arguments was
        // silently fabricated -- a brand-new, uninitialized TranslationManager with no locales loaded,
        // answering an optional dependency that nobody configured. "Not bound" has to mean absent, or
        // every caller of this method has to know which of the two shapes it asked for.
        if (!$this->has($id)) {
            return null;
        }

        try {
            return $this->get($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * PSR-11 has(): reflects only explicitly registered entries (definitions/aliases),
     * not autowireable classes. Use canAutowire() for the internal autowiring path.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->definitions) || isset($this->aliases[$id]);
    }

    /**
     * Drops request-scoped resolved instances (called on worker-mode request boundaries).
     * Singletons and definitions are untouched.
     */
    public function reset(): void
    {
        $this->requestResolved = [];
        $this->resolvingStack = [];
    }

    /**
     * Build a fresh, never-cached instance of $class.
     * The public entry point for per-execution objects — actions and views — which must
     * never be memoized the way get() memoizes services: each dispatch needs its own
     * instance regardless of scope.
     * $extraParams is an explicit construction-time override, matched by constructor
     * parameter name OR by parameter type (the .NET `ActivatorUtilities.CreateInstance`
     * pattern: `make($class, [SomeType::class => $value])`), and takes priority over
     * #[Inject]/#[Autowire] attributes and type-hinted autowiring.
     * A class with no constructor is `new`'d directly — zero behavior change and zero
     * migration burden for the untouched majority of actions/views.
     *
     * Not container-cached at all, which is also what makes it the safe way to build something
     * needing request-scoped collaborators: the captive-dependency guard has nothing to refuse,
     * because the result is never held past the call.
     *
     * @template   T of object
     * @param      class-string<T> $class
     * @param      array<string, mixed> $extraParams
     * @return     T
     */
    public function make(string $class, array $extraParams = []): object
    {
        return $this->autoWire($class, $extraParams, null, $this->getReflectionClass($class));
    }

    /**
     * Reflect $class, or refuse. The assertion states what the throw already guarantees: past this
     * call the name is known to be a real class, which is what lets the autowiring path below be
     * typed generically.
     *
     * @phpstan-assert class-string $class
     * @return \ReflectionClass<object>
     */
    private function getReflectionClass(string $class): \ReflectionClass
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new ContainerException(sprintf('Class "%s" does not exist', $class));
        }
        return $this->reflectionCache[$class] ??= new \ReflectionClass($class);
    }

    private function canAutowire(string $id): bool
    {
        if ($this->has($id)) {
            return true;
        }
        return class_exists($id) && $this->getReflectionClass($id)->isInstantiable();
    }

    /**
     * @return array{0: mixed, 1: string} [instance, scope]
     */
    private function build(string $lookupId, string $requestedId): array
    {
        if (array_key_exists($lookupId, $this->definitions)) {
            $def = $this->definitions[$lookupId];
            $concrete = $def['concrete'];
            $scope = $def['scope'];
            $params = $def['params'];

            if ($concrete instanceof \Closure || (is_callable($concrete) && !is_string($concrete))) {
                try {
                    $obj = $concrete($this);
                } catch (\Throwable $e) {
                    throw new ContainerException("Error while invoking factory for '$requestedId': " . $e->getMessage(), 0, $e);
                }
            } elseif (is_string($concrete) && class_exists($concrete)) {
                $obj = $this->autoWire($concrete, $params, $requestedId, null, $scope);
            } else {
                $obj = $concrete; // instance or scalar
            }

            return [$obj, $scope];
        }

        if ($this->canAutowire($lookupId)) {
            $rc = $this->getReflectionClass($lookupId);
            $scope = $this->resolveDefaultScope($rc);
            return [$this->autoWire($lookupId, [], $requestedId, $rc, $scope), $scope];
        }

        throw new NotFoundException("Service '$requestedId' not found and no autowireable class/alias exists");
    }

    /**
     * Default scope for an unregistered, autowired class: #[Service(scope: ...)] wins if
     * present; otherwise a class implementing ServiceInterface defaults to
     * transient — services are transient today (as models, none are
     * ISingletonModel), and silently promoting one to a process singleton under
     * FrankenPHP is a latent cross-request bug. A bare #[Service] answers transient
     * too, so declaring a service by attribute and declaring one by interface agree.
     *
     * Anything else defaults to REQUEST. Singleton was the pre-Phase-3 fallback, but it
     * is the wrong default for a class nobody has vetted: under a persistent worker an
     * unregistered class that happens to hold per-request state (or captures something
     * that does) silently serves request N's data to request N+1. Request scope makes
     * the unvetted case safe by construction — the instance is dropped by reset() at the
     * request boundary — and matches the documented rule of thumb that singleton is for
     * objects you have *confirmed* hold no per-request state. Opt back in explicitly with
     * #[Service(scope: Container::SCOPE_SINGLETON)] or an explicit set() registration.
     */
    /**
     * @param \ReflectionClass<object> $rc
     */
    private function resolveDefaultScope(\ReflectionClass $rc): string
    {
        $serviceAttr = $rc->getAttributes(\Quiote\DI\Attribute\Service::class);
        if ($serviceAttr) {
            return $serviceAttr[0]->newInstance()->scope;
        }
        if ($rc->implementsInterface(\Quiote\Service\ServiceInterface::class)) {
            return self::SCOPE_TRANSIENT;
        }
        return self::SCOPE_REQUEST;
    }

    /**
     * The scope $id was *explicitly* given, or null when nothing declared one.
     *
     * Deliberately distinct from {@see resolveDefaultScope()}, which always answers with
     * something. The captive-dependency guard must only fire on a scope somebody actually
     * asked for: treating the inferred request-scope default as a declaration would make
     * every singleton that autowires an ordinary unregistered helper class throw.
     */
    private function declaredScopeOf(string $id): ?string
    {
        $lookupId = $this->aliases[$id] ?? $id;

        if (array_key_exists($lookupId, $this->definitions)) {
            return $this->definitions[$lookupId]['scope'];
        }

        if (class_exists($lookupId)) {
            $serviceAttr = $this->getReflectionClass($lookupId)->getAttributes(\Quiote\DI\Attribute\Service::class);
            if ($serviceAttr) {
                return $serviceAttr[0]->newInstance()->scope;
            }
        }

        return null;
    }

    /**
     * Refuse to inject a request-scoped service into a singleton — the "captive
     * dependency" problem.
     *
     * A singleton is constructed once and never rebuilt, so whatever it is handed at
     * construction it keeps forever. {@see reset()} drops the container's own reference
     * to a request-scoped instance at the request boundary, but it cannot reach into a
     * singleton that already captured one. Under a persistent worker the singleton then
     * serves request 1's instance to every later request.
     *
     * For the services Context registers request-scoped — `request`, `user`, `sessionBag`,
     * and their concrete class names — that is a cross-user identity leak, exactly the
     * failure {@see \Quiote\Context::clearRequestScopedState()} exists to prevent. It is
     * the one path into that state the context's own clearing cannot cover, so it is
     * refused at wiring time rather than detected later.
     */
    private function guardCaptiveDependency(
        string $dependencyId,
        ?string $consumerScope,
        string $class,
        string $paramName,
    ): void {
        if ($consumerScope !== self::SCOPE_SINGLETON) {
            return;
        }
        if ($this->declaredScopeOf($dependencyId) !== self::SCOPE_REQUEST) {
            return;
        }

        throw new ContainerException(sprintf(
            "Cannot autowire '%s': it is singleton-scoped but parameter $%s depends on '%s', which is "
            . 'request-scoped. The singleton would capture one request\'s instance and keep serving it to every '
            . 'later request in a persistent worker.%s Failing that, give %s request or transient scope, or '
            . 'inject a factory and resolve %s per call.',
            $class,
            $paramName,
            $dependencyId,
            $this->captiveDependencyHint($dependencyId),
            $class,
            $dependencyId,
        ));
    }

    /**
     * Name the accessor to inject instead, for the request-scoped services that have one.
     *
     * The guard is correct but says nothing about what to do about it, and for the request and the
     * user the answer is specific: an accessor that resolves per call rather than capturing. Worth
     * putting in the message, because this is a wiring-time failure someone hits once and has to
     * guess their way out of.
     */
    private function captiveDependencyHint(string $dependencyId): string
    {
        $accessor = match (true) {
            $dependencyId === 'request'
                || is_a($dependencyId, \Quiote\Request\WebRequest::class, true)
                => \Quiote\Request\RequestState::class,
            $dependencyId === 'user'
                || is_a($dependencyId, \Quiote\User\User::class, true)
                || is_a($dependencyId, \Quiote\User\ISecurityUser::class, true)
                => \Quiote\User\CurrentUser::class,
            default => null,
        };

        return $accessor === null
            ? ''
            : sprintf(' Inject %s instead; it resolves per call and holds nothing.', $accessor);
    }

    /**
     * $consumerScope is the scope the finished object will be cached under, threaded in so
     * {@see guardCaptiveDependency()} can refuse a request-scoped dependency for a singleton.
     * Null means "not container-cached at all" — the {@see make()} path for actions and
     * views, which are per-execution and may freely depend on request-scoped services.
     * @template   T of object
     * @param      class-string<T> $class
     * @param      array<string, mixed> $params
     * @param      \ReflectionClass<object>|null $rc
     * @return     T
     */
    private function autoWire(
        string $class,
        array $params,
        ?string $requestedId = null,
        ?\ReflectionClass $rc = null,
        ?string $consumerScope = null,
    ): object {
        $rc ??= $this->getReflectionClass($class);
        $plan = $this->classPlan($class, $rc);

        if ($plan['ctorParams'] === null) {
            $obj = new $class();
        } else {
            $args = [];
            foreach ($plan['ctorParams'] as $p) {
                $args[] = $this->resolveParamValue($p, $params, $class, $requestedId, $consumerScope);
            }
            try {
                // `new $class(...)` rather than $rc->newInstanceArgs(): identical for the public
                // constructor this path requires, and it is what makes the instance provably an
                // instance of $class rather than a bare object.
                $obj = new $class(...$args);
            } catch (\Throwable $e) {
                throw new ContainerException("Failed constructing '$class': " . $e->getMessage(), 0, $e);
            }
        }
        // #[Required] setter/method injection (usually none — plan caches an empty list).
        foreach ($plan['required'] as $req) {
            $args = [];
            foreach ($req['params'] as $p) {
                $args[] = $this->resolveParamValue($p, [], $class, null, $consumerScope);
            }
            $req['method']->invoke($obj, ...$args);
        }
        return $obj;
    }

    /**
     * Immutable per-class instantiation plan, computed once per process (class
     * metadata never changes at runtime, so this is FrankenPHP-worker safe like
     * reflectionCache). Caches the constructor's parameter list (or null when
     * the class has no constructor) and the list of #[Required] methods to call
     * after construction, with each method's parameters. This hoists the
     * getConstructor()/getParameters() calls and — the real per-request win —
     * the getMethods(IS_PUBLIC) + getAttributes(Required::class) scan that
     * previously ran on every make()/autowire (i.e. every action instantiation
     * per request). The #[Required] guard is evaluated here, once, instead of
     * on every invocation.
     *
     * @param \ReflectionClass<object> $rc
     * @return array{ctorParams: \ReflectionParameter[]|null, required: array<array{method: \ReflectionMethod, params: \ReflectionParameter[]}>}
     */
    private function classPlan(string $class, \ReflectionClass $rc): array
    {
        if (isset($this->planCache[$class])) {
            return $this->planCache[$class];
        }
        $ctor = $rc->getConstructor();
        $required = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || !$method->getAttributes(Required::class)) {
                continue;
            }
            $this->guardRequiredMethod($method, $class);
            $required[] = ['method' => $method, 'params' => $method->getParameters()];
        }
        return $this->planCache[$class] = [
            'ctorParams' => $ctor ? $ctor->getParameters() : null,
            'required' => $required,
        ];
    }

    /**
     * Resolves a single constructor/#[Required]-method parameter, in priority order:
     * explicit registration-time param binding, #[Inject]/#[Autowire] attribute override,
     * type-hinted autowiring, constructor default, or a loud ContainerException.
     */
    /**
     * @param array<string, mixed> $params
     */
    private function resolveParamValue(
        \ReflectionParameter $p,
        array $params,
        string $class,
        ?string $requestedId,
        ?string $consumerScope = null,
    ): mixed {
        $name = $p->getName();
        if (array_key_exists($name, $params)) {
            return $params[$name];
        }

        $type = $p->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && array_key_exists($type->getName(), $params)) {
            return $params[$type->getName()];
        }

        $injectAttrs = $p->getAttributes(Inject::class);
        if ($injectAttrs) {
            $injectId = $injectAttrs[0]->newInstance()->id;
            $this->guardCaptiveDependency($injectId, $consumerScope, $class, $name);
            return $this->get($injectId);
        }

        $autowireAttrs = $p->getAttributes(Autowire::class);
        if ($autowireAttrs) {
            return $autowireAttrs[0]->newInstance()->value;
        }

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $dep = $type->getName();
            if ($this->canAutowire($dep)) {
                $this->guardCaptiveDependency($dep, $consumerScope, $class, $name);
                return $this->get($dep);
            }
            if ($p->isDefaultValueAvailable()) {
                return $p->getDefaultValue();
            }
            throw new ContainerException("Cannot autowire '$class': unsatisfied dependency '" . $dep . "' for parameter $" . $name . " (requested as '$requestedId')");
        }

        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }
        throw new ContainerException("Cannot autowire '$class': untyped parameter $" . $name . " without default (requested as '$requestedId')");
    }

    /**
     * #[Required] methods are for container-owned deps only. initialize() is a framework
     * lifecycle hook invoked by the *executor* with a per-execution context the container
     * does not own; letting the container also call it (and try to autowire
     * ActionInitContext/ViewInitContext) is a category error. Reject on either signal —
     * the method name (the common case) or a type-hint on a forbidden context type (the
     * robust case: a differently-named #[Required] setter taking ActionInitContext is
     * still always wrong, regardless of what it's called).
     */
    private function guardRequiredMethod(\ReflectionMethod $method, string $class): void
    {
        if ($method->getName() === 'initialize') {
            throw new ContainerException(
                "Cannot autowire '$class': #[Required] method 'initialize()' is a framework lifecycle hook " .
                "invoked by the executor with a per-execution context the container does not own. " .
                "Use constructor injection or a differently named #[Required] setter instead.",
            );
        }
        foreach ($method->getParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && in_array($type->getName(), self::FORBIDDEN_REQUIRED_CONTEXT_TYPES, true)) {
                throw new ContainerException(
                    "Cannot autowire '$class': #[Required] method '" . $method->getName() . "()' type-hints '" . $type->getName() . "', " .
                    "a per-execution context the container does not own. " .
                    "Use constructor injection or a differently named #[Required] setter instead.",
                );
            }
        }
    }
}
