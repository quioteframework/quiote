<?php

namespace Agavi\DI;

use Agavi\DI\Attribute\Autowire;
use Agavi\DI\Attribute\Inject;
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
    private const FORBIDDEN_REQUIRED_CONTEXT_TYPES = [
        \Agavi\Execution\ActionInitContext::class,
        \Agavi\Execution\ViewInitContext::class,
    ];

    private array $definitions = [];
    private array $aliases = [];
    private array $singletonResolved = [];
    private array $requestResolved = [];
    private array $resolvingStack = [];

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

    private function canAutowire(string $id): bool
    {
        if ($this->has($id)) {
            return true;
        }
        return class_exists($id) && (new \ReflectionClass($id))->isInstantiable();
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
            $rc = new \ReflectionClass($lookupId);
            return [$this->autoWire($lookupId, [], $requestedId, $rc), $this->resolveDefaultScope($rc)];
        }

        throw new NotFoundException("Service '$requestedId' not found and no autowireable class/alias exists");
    }

    /**
     * Default scope for an unregistered, autowired class: #[Service(scope: ...)] wins if
     * present; otherwise a class implementing AgaviServiceInterface (docs/DI_MIGRATION_PLAN.md,
     * Phase 3) defaults to transient — services are transient today (as models, none are
     * AgaviISingletonModel), and silently promoting one to a process singleton under
     * FrankenPHP is a latent cross-request bug. Anything else defaults to singleton, matching
     * this container's pre-Phase-3 autowire-fallback behavior.
     */
    private function resolveDefaultScope(\ReflectionClass $rc): string
    {
        $serviceAttr = $rc->getAttributes(\Agavi\DI\Attribute\Service::class);
        if ($serviceAttr) {
            return $serviceAttr[0]->newInstance()->scope;
        }
        if ($rc->implementsInterface(\Agavi\Service\AgaviServiceInterface::class)) {
            return self::SCOPE_TRANSIENT;
        }
        return self::SCOPE_SINGLETON;
    }

    private function autoWire(string $class, array $params, ?string $requestedId = null, ?\ReflectionClass $rc = null): object
    {
        $rc ??= new \ReflectionClass($class);
        $ctor = $rc->getConstructor();
        if (!$ctor) {
            $obj = new $class();
        } else {
            $args = [];
            foreach ($ctor->getParameters() as $p) {
                $args[] = $this->resolveParamValue($p, $params, $class, $requestedId);
            }
            try {
                $obj = $rc->newInstanceArgs($args);
            } catch (\Throwable $e) {
                throw new ContainerException("Failed constructing '$class': " . $e->getMessage(), 0, $e);
            }
        }
        $this->invokeRequiredMethods($rc, $obj, $class);
        return $obj;
    }

    /**
     * Resolves a single constructor/#[Required]-method parameter, in priority order:
     * explicit registration-time param binding, #[Inject]/#[Autowire] attribute override,
     * type-hinted autowiring, constructor default, or a loud ContainerException.
     */
    private function resolveParamValue(\ReflectionParameter $p, array $params, string $class, ?string $requestedId): mixed
    {
        $name = $p->getName();
        if (array_key_exists($name, $params)) {
            return $params[$name];
        }

        $injectAttrs = $p->getAttributes(Inject::class);
        if ($injectAttrs) {
            return $this->get($injectAttrs[0]->newInstance()->id);
        }

        $autowireAttrs = $p->getAttributes(Autowire::class);
        if ($autowireAttrs) {
            return $autowireAttrs[0]->newInstance()->value;
        }

        $type = $p->getType();
        if ($type && !$type->isBuiltin()) {
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
     * After construction, calls every #[Required]-marked method with container-autowired
     * args — optional setter/method injection for cross-cutting deps (e.g. a logger) that
     * shouldn't clutter every constructor (same mechanism Symfony uses for
     * AbstractController::setContainer()).
     */
    private function invokeRequiredMethods(\ReflectionClass $rc, object $obj, string $class): void
    {
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || !$method->getAttributes(Required::class)) {
                continue;
            }
            $this->guardRequiredMethod($method, $class);
            $args = [];
            foreach ($method->getParameters() as $p) {
                $args[] = $this->resolveParamValue($p, [], $class, null);
            }
            $method->invoke($obj, ...$args);
        }
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
            if ($type && !$type->isBuiltin() && in_array($type->getName(), self::FORBIDDEN_REQUIRED_CONTEXT_TYPES, true)) {
                throw new ContainerException(
                    "Cannot autowire '$class': #[Required] method '" . $method->getName() . "()' type-hints '" . $type->getName() . "', " .
                    "a per-execution context the container does not own. " .
                    "Use constructor injection or a differently named #[Required] setter instead.",
                );
            }
        }
    }
}
