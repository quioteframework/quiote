<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

/**
 * Format-independent description of one route, whatever front-end produced
 * it (today: a #[Route] attribute; later, possibly routing.xml or a
 * programmatic builder). Every back-end
 * (RouteCollectionBuilder, a compiled-matcher emitter, routes:list) consumes
 * this shape and never needs to know the source.
 * @since      1.0.0
 */
final class RouteDefinition
{
	/**
	 * @param string[] $methods
	 * @param array<string,mixed> $defaults
	 * @param array<string,string> $requirements
	 * @param array{gen_path:string,cut:bool,path:string} $meta Quiote's own
	 *        reverse-routing meta, in the shape Routing::gen() expects.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $path,
		public readonly string $module,
		public readonly string $action,
		public readonly array $methods,
		public readonly array $defaults,
		public readonly array $requirements,
		public readonly ?string $host,
		public readonly ?string $condition,
		public readonly int $priority,
		public readonly ?string $outputType,
		public readonly array $meta,
		public readonly string $sourceRef,
	) {
	}
}
