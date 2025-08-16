<?php
namespace Agavi\DI;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface {}
class ContainerException extends \RuntimeException implements ContainerExceptionInterface {}

/**
 * Extremely small DI container: supports definitions as closures, class names, or instances.
 */
class Container implements ContainerInterface
{
    private array $definitions = [];
    private array $resolved = [];
    private array $aliases = [];

    public function set(string $id, mixed $concrete): void { $this->definitions[$id] = $concrete; }

    public function alias(string $abstract, string $concrete): void { $this->aliases[$abstract] = $concrete; }

    public function setFactory(string $id, callable $factory): void { $this->definitions[$id] = $factory; }

    public function get(string $id): mixed
    {
        if(isset($this->resolved[$id])) return $this->resolved[$id];
        $lookupId = $this->aliases[$id] ?? $id;
        if(!array_key_exists($lookupId, $this->definitions)) {
            if(class_exists($lookupId)) { // auto-wire
                $obj = $this->autoWire($lookupId, $id);
                return $this->resolved[$id] = $obj;
            }
            throw new NotFoundException("Service '$id' not found and no autowireable class/alias exists");
        }
        $def = $this->definitions[$lookupId];
        if(is_callable($def)) {
            try {
                $obj = $def($this);
            } catch(\Throwable $e) {
                throw new ContainerException("Error while invoking factory for '$id': ".$e->getMessage(), 0, $e);
            }
        } elseif(is_string($def) && class_exists($def)) {
            $obj = $this->autoWire($def, $id);
        } else {
            $obj = $def; // instance or scalar
        }
        return $this->resolved[$id] = $obj;
    }

    public function has(string $id): bool
    { return isset($this->definitions[$id]) || isset($this->resolved[$id]) || isset($this->aliases[$id]) || class_exists($id) || (isset($this->aliases[$id]) && class_exists($this->aliases[$id])); }

    private function autoWire(string $class, ?string $requestedId = null): object
    {
        $rc = new \ReflectionClass($class);
        $ctor = $rc->getConstructor();
        if(!$ctor) return new $class();
        $args = [];
        foreach($ctor->getParameters() as $p) {
            $type = $p->getType();
            if($type && !$type->isBuiltin()) {
                $dep = $type->getName();
                if($this->has($dep)) {
                    $args[] = $this->get($dep);
                } elseif($p->isDefaultValueAvailable()) {
                    $args[] = $p->getDefaultValue();
                } else {
                    throw new ContainerException("Cannot autowire '$class': unsatisfied dependency '".$dep."' for parameter $".$p->getName()." (requested as '$requestedId')");
                }
            } else {
                if($p->isDefaultValueAvailable()) {
                    $args[] = $p->getDefaultValue();
                } else {
                    throw new ContainerException("Cannot autowire '$class': untyped parameter $".$p->getName()." without default (requested as '$requestedId')");
                }
            }
        }
        try {
            return $rc->newInstanceArgs($args);
        } catch(\Throwable $e) {
            throw new ContainerException("Failed constructing '$class': ".$e->getMessage(), 0, $e);
        }
    }
}
