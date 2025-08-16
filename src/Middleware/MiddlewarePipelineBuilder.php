<?php
namespace Agavi\Middleware;

use Psr\Http\Server\RequestHandlerInterface;
use Agavi\Middleware\Attribute\AgaviMiddleware;
use ReflectionClass;
use Psr\Container\ContainerInterface;

/**
 * Scans given class list (or namespaces) for #[AgaviMiddleware] and builds a pipeline.
 * Simple implementation; integration code should provide class list discovery.
 */
class MiddlewarePipelineBuilder
{
    /** @param array<class-string,string>|class-string[] $classes Map of class=>name or numeric array of class names */
    public static function fromClasses(array $classes, RequestHandlerInterface $finalHandler, ?ContainerInterface $container = null, ?callable $factory = null): MiddlewarePipeline
    {
        $pipeline = new MiddlewarePipeline($finalHandler);
        foreach($classes as $key => $value) {
            $class = is_string($key) && class_exists($key) ? $key : $value; // support both styles
            if(!class_exists($class)) continue;
            $rc = new ReflectionClass($class);
            $attr = $rc->getAttributes(AgaviMiddleware::class)[0] ?? null;
            if(!$attr) continue;
            /** @var AgaviMiddleware $meta */
            $meta = $attr->newInstance();
            if(!$meta->enabled) continue;
            $creator = $factory ?? function($cls) use ($container) {
                if($container && $container->has($cls)) {
                    return $container->get($cls);
                }
                return new $cls(...MiddlewarePipelineBuilder::resolveConstructorArgs($cls, $container));
            };
            $instance = $creator($class);
            $name = is_string($key) && class_exists($key) ? $value : $rc->getShortName();
            $pipeline->add($name, $instance, $meta->phase, $meta->priority, $meta->before, $meta->after);
        }
        return $pipeline;
    }

    /** Resolve constructor args using container where possible */
    private static function resolveConstructorArgs(string $cls, ?ContainerInterface $container = null): array
    {
        $rc = new ReflectionClass($cls);
        $ctor = $rc->getConstructor();
        if(!$ctor) return [];
        $args = [];
        foreach($ctor->getParameters() as $p) {
            if($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
            } else {
                $type = $p->getType();
                if($type && !$type->isBuiltin()) {
                    $dep = $type->getName();
                    if($container && $container->has($dep)) {
                        $args[] = $container->get($dep);
                        continue;
                    }
                }
                $args[] = null;
            }
        }
        return $args;
    }
}
