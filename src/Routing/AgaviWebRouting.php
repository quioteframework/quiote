<?php
declare(strict_types=1);
namespace Agavi\Routing;

use Symfony\Contracts\Service\ResetInterface;

/** Minimal legacy stub preserved only so old class names autoload without errors. */
class AgaviWebRouting extends AgaviRouting implements ResetInterface
{
	protected function build(): array { return [new \Symfony\Component\Routing\RouteCollection(), []]; }
	#[\Override]
    public function reset(): void {}
}