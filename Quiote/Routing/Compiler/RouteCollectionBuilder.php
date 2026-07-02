<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

/**
 * Runtime back-end: turns a RoutePlan into the [RouteCollection, meta] pair
 * Routing::build() already expects (the same pair the committed
 * Routing/Generated/*Routes::addRoutes() files produce). Neither Routing nor
 * the Symfony UrlMatcher built from its output care that these routes came
 * from #[Route] attributes instead of hand-generated PHP.
 * @since      1.0.0
 */
final class RouteCollectionBuilder
{
	/**
	 * @return array{0:RouteCollection,1:array<string,array{gen_path:string,cut:bool,path:string}>}
	 */
	public function build(RoutePlan $plan): array
	{
		$routes = new RouteCollection();
		$meta = [];
		$this->mergeInto($routes, $meta, $plan);

		return [$routes, $meta];
	}

	/**
	 * Adds a RoutePlan's routes into an already-populated RouteCollection +
	 * meta pair, instead of building a fresh one. This is what lets a
	 * hand-written Routing subclass declare some routes programmatically and
	 * pull in #[Route]-attributed ones for the rest, in the same
	 * RouteCollection -- see Quiote\Routing\AttributeRoutes::mergeInto() for
	 * the usual entrypoint.
	 * @param array<string,array{gen_path:string,cut:bool,path:string}> $meta
	 */
	public function mergeInto(RouteCollection $routes, array &$meta, RoutePlan $plan): void
	{
		foreach ($plan->routes as $definition) {
			$defaults = $definition->defaults;
			$defaults['_module'] = $definition->module;
			$defaults['_action'] = $definition->action;
			if ($definition->outputType !== null) {
				$defaults['_output_type'] = $definition->outputType;
			}

			$route = new SymfonyRoute(
				$definition->path,
				$defaults,
				$definition->requirements,
				[],
				$definition->host ?? '',
				[],
				$definition->methods,
				$definition->condition ?? ''
			);

			$routes->add($definition->name, $route, $definition->priority);
			$meta[$definition->name] = $definition->meta;
		}
	}
}
