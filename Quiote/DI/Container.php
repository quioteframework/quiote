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

    public function alias(string $abstract, string $concrete): void
    {
        $this->aliases[$abstract] = $concrete;
    }

    public function setFactory(string $id, callable $factory, string $scope = self::SCOPE_SINGLETON): void
    {
        $this->set($id, $factory, $scope);
    }

    public function get(string $id): mixed
    {
        $lookupId = $this->aliases[$id] ?? $id;

        if (array_key_exists($lookupId, $this->singletonResolved)) {
            return $this->singletonResolved[$lookupId];
        }
        if (array_key_exists($lookupId, $this->requestResolved)) {
            return $this->requestResolved[$lookupId];
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

        return $obj;
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
     */
    /**
     * @param array<string, mixed> $extraParams
     */
    public function make(string $class, array $extraParams = []): object
    {
        return $this->autoWire($class, $extraParams, null, $this->getReflectionClass($class));
    }

    /**
     * @return \ReflectionClass<object>
     */
    private function getReflectionClass(string $class): \ReflectionClass
    {
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
                $obj = $this->autoWire($concrete, $params, $requestedId);
            } else {
                $obj = $concrete; // instance or scalar
            }

            return [$obj, $scope];
        }

        if ($this->canAutowire($lookupId)) {
            $rc = $this->getReflectionClass($lookupId);
            return [$this->autoWire($lookupId, [], $requestedId, $rc), $this->resolveDefaultScope($rc)];
        }

        throw new NotFoundException("Service '$requestedId' not found and no autowireable class/alias exists");
    }

    /**
     * Default scope for an unregistered, autowired class: #[Service(scope: ...)] wins if
     * present; otherwise a class implementing ServiceInterface defaults to
     * transient — services are transient today (as models, none are
     * ISingletonModel), and silently promoting one to a process singleton under
     * FrankenPHP is a latent cross-request bug. Anything else defaults to singleton, matching
     * this container's pre-Phase-3 autowire-fallback behavior.
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
        return self::SCOPE_SINGLETON;
    }

    /**
     * @param array<string, mixed> $params
     * @param \ReflectionClass<object>|null $rc
     */
    private function autoWire(string $class, array $params, ?string $requestedId = null, ?\ReflectionClass $rc = null): object
    {
        $rc ??= $this->getReflectionClass($class);
        $plan = $this->classPlan($class, $rc);

        if ($plan['ctorParams'] === null) {
            $obj = new $class();
        } else {
            $args = [];
            foreach ($plan['ctorParams'] as $p) {
                $args[] = $this->resolveParamValue($p, $params, $class, $requestedId);
            }
            try {
                $obj = $rc->newInstanceArgs($args);
            } catch (\Throwable $e) {
                throw new ContainerException("Failed constructing '$class': " . $e->getMessage(), 0, $e);
            }
        }
        // #[Required] setter/method injection (usually none — plan caches an empty list).
        foreach ($plan['required'] as $req) {
            $args = [];
            foreach ($req['params'] as $p) {
                $args[] = $this->resolveParamValue($p, [], $class, null);
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
    private function resolveParamValue(\ReflectionParameter $p, array $params, string $class, ?string $requestedId): mixed
    {
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
            return $this->get($injectAttrs[0]->newInstance()->id);
        }

        $autowireAttrs = $p->getAttributes(Autowire::class);
        if ($autowireAttrs) {
            return $autowireAttrs[0]->newInstance()->value;
        }

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            $dep = $type->getName();
            if ($this->canAutowire($dep)) {
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
