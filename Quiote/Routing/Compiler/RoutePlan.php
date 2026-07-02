<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

/**
 * The routing IR: an ordered set of RouteDefinitions gathered from one or
 * more sources (today: an AttributeRouteScanner pass). Both back-ends
 * (RouteCollectionBuilder for the runtime, a future compiled-matcher
 * emitter for `routes:compile`) consume this and this alone.
 * @since      1.0.0
 */
final class RoutePlan
{
	/**
	 * @param RouteDefinition[] $routes
	 */
	public function __construct(
		public readonly array $routes,
		public readonly string $sourceRef,
	) {
	}
}
