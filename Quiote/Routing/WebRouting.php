<?php
declare(strict_types=1);
namespace Quiote\Routing;

use Symfony\Contracts\Service\ResetInterface;

/** Minimal legacy stub preserved only so old class names autoload without errors. */
class WebRouting extends Routing implements ResetInterface
{
	/**
	 * @return array{0: \Symfony\Component\Routing\RouteCollection, 1: array<mixed>}
	 */
	protected function build(): array { return [new \Symfony\Component\Routing\RouteCollection(), []]; }
	#[\Override]
    public function reset(): void {}
}