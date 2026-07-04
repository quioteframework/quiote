<?php
declare(strict_types=1);

namespace Quiote\Routing;

use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Routing\Compiler\RouteCollectionBuilder;
use Quiote\Support\Compiler\Diagnostic;

/**
 * Opt-in Routing implementation: builds its RouteCollection + meta by
 * scanning #[Route] attributes on action classes instead of a committed
 * Routing/Generated/ tree. An app switches to attribute routing by
 * extending this (or using it directly) in place of its own
 * generated-routes subclass. A future `routes:compile` artifact is
 * expected to supersede the live scan done here for production, with
 * this remaining the always-correct fallback.
 * @since      1.0.0
 */
class AttributeRouting extends Routing
{
	/** @var Diagnostic[] */
	private array $diagnostics = [];

	#[\Override]
	protected function build(): array
	{
		$scanner = new AttributeRouteScanner();
		$plan = $scanner->scan($this->moduleDirs());
		$this->diagnostics = $scanner->getDiagnostics();

		return (new RouteCollectionBuilder())->build($plan);
	}

	/**
	 * @return Diagnostic[] Diagnostics recorded while scanning for routes.
	 */
	public function getDiagnostics(): array
	{
		return $this->diagnostics;
	}

	/**
	 * @return iterable<string>|null Module directories to scan; null defers
	 *         to AttributeRouteScanner's own default (core.module_dir).
	 */
	protected function moduleDirs(): ?iterable
	{
		return null;
	}
}
