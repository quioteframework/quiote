<?php

namespace Agavi\DI;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

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
            return [$this->autoWire($lookupId, [], $requestedId), self::SCOPE_SINGLETON];
        }

        throw new NotFoundException("Service '$requestedId' not found and no autowireable class/alias exists");
    }

    private function autoWire(string $class, array $params, ?string $requestedId = null): object
    {
        $rc = new \ReflectionClass($class);
        $ctor = $rc->getConstructor();
        if (!$ctor) {
            return new $class();
        }
        $args = [];
        foreach ($ctor->getParameters() as $p) {
            if (array_key_exists($p->getName(), $params)) {
                $args[] = $params[$p->getName()];
                continue;
            }
            $type = $p->getType();
            if ($type && !$type->isBuiltin()) {
                $dep = $type->getName();
                if ($this->canAutowire($dep)) {
                    $args[] = $this->get($dep);
                } elseif ($p->isDefaultValueAvailable()) {
                    $args[] = $p->getDefaultValue();
                } else {
                    throw new ContainerException("Cannot autowire '$class': unsatisfied dependency '" . $dep . "' for parameter $" . $p->getName() . " (requested as '$requestedId')");
                }
            } else {
                if ($p->isDefaultValueAvailable()) {
                    $args[] = $p->getDefaultValue();
                } else {
                    throw new ContainerException("Cannot autowire '$class': untyped parameter $" . $p->getName() . " without default (requested as '$requestedId')");
                }
            }
        }
        try {
            return $rc->newInstanceArgs($args);
        } catch (\Throwable $e) {
            throw new ContainerException("Failed constructing '$class': " . $e->getMessage(), 0, $e);
        }
    }
}
