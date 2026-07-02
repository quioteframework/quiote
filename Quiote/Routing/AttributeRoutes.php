<?php
declare(strict_types=1);

namespace Quiote\Routing;

use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Routing\Compiler\RouteCollectionBuilder;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Routing\RouteCollection;

/**
 * Entry point for combining #[Route]-attributed actions with hand-written
 * routes in the same Routing subclass. Attribute routing
 * (Quiote\Routing\AttributeRouting) and programmatic/file-based routing (a
 * plain Routing::build() like samples/app's AppRouting) are not mutually
 * exclusive: a Routing::build() implementation can add its own routes to a
 * RouteCollection by hand and then call mergeInto() to pull in every
 * #[Route]-attributed action on top, all in one RouteCollection + meta pair.
 * @since      1.0.0
 */
final class AttributeRoutes
{
	/**
	 * @param array<string,array{gen_path:string,cut:bool,path:string}> $meta
	 * @param iterable<string>|null $moduleDirs Defaults to [core.module_dir].
	 * @return Diagnostic[] Diagnostics recorded while scanning (duplicate
	 *         route names/paths among the attribute routes themselves --
	 *         collisions against the hand-written routes already in $routes
	 *         are not detected here, same as two hand-written addRoute()
	 *         calls with the same name silently overwriting each other).
	 */
	public static function mergeInto(RouteCollection $routes, array &$meta, ?iterable $moduleDirs = null): array
	{
		$scanner = new AttributeRouteScanner();
		$plan = $scanner->scan($moduleDirs);
		(new RouteCollectionBuilder())->mergeInto($routes, $meta, $plan);

		return $scanner->getDiagnostics();
	}
}
