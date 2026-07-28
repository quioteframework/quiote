<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

/**
 * The inverse of {@see RouteCollectionBuilder}: lifts the live
 * RouteCollection a configured Routing service exposes back into the routing
 * IR. That matters for anything that wants to describe *every* route the app
 * actually serves -- OpenAPI generation, say -- rather than only the
 * `#[Route]`-attributed subset an {@see AttributeRouteScanner} pass finds:
 * a hand-written `Routing::build()`, a committed
 * `Routing/Generated/*Routes::addRoutes()` file and merged attribute routes
 * all end up in the same collection, and all come back out as
 * RouteDefinitions here.
 *
 * `module`/`action`/`outputType` are read back from the `_module`/`_action`/
 * `_output_type` defaults RouteCollectionBuilder writes (and which Quiote's
 * own dispatch reads), and are dropped from the reported `defaults` so a
 * consumer sees them once, in one place. Nothing that only exists in the
 * source IR -- priority, the source file a route was declared in -- survives
 * a round trip through a RouteCollection, so those come back as 0 and the
 * collection's own source ref.
 * @since      1.2.5
 */
final class RouteCollectionIntrospector
{
    /**
     * @return RouteDefinition[] In the collection's own (priority-resolved) order.
     */
    public function toDefinitions(RouteCollection $collection, string $sourceRef = 'RouteCollection'): array
    {
        $definitions = [];
        foreach ($collection->all() as $name => $route) {
            $definitions[] = $this->toDefinition((string) $name, $route, $sourceRef);
        }

        return $definitions;
    }

    private function toDefinition(string $name, SymfonyRoute $route, string $sourceRef): RouteDefinition
    {
        $defaults = $route->getDefaults();
        $module = $this->stringDefault($defaults, '_module');
        $action = $this->stringDefault($defaults, '_action');
        $outputType = $this->stringDefault($defaults, '_output_type');
        unset($defaults['_module'], $defaults['_action'], $defaults['_output_type']);

        $requirements = [];
        foreach ($route->getRequirements() as $key => $requirement) {
            $requirements[(string) $key] = (string) $requirement;
        }

        $host = $route->getHost();
        $condition = $route->getCondition();

        return new RouteDefinition(
            $name,
            $route->getPath(),
            $module,
            $action,
            array_values(array_map(strval(...), $route->getMethods())),
            $defaults,
            $requirements,
            $host !== '' ? $host : null,
            $condition !== '' ? $condition : null,
            0,
            $outputType !== '' ? $outputType : null,
            [
                'gen_path' => $route->getPath(),
                'cut' => false,
                'path' => $route->getPath(),
            ],
            $sourceRef,
        );
    }

    /** @param array<string, mixed> $defaults */
    private function stringDefault(array $defaults, string $key): string
    {
        $value = $defaults[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
