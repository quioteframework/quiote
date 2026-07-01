<?php
declare(strict_types=1);
namespace Quiote\Routing;

use Symfony\Contracts\Service\ResetInterface;

/** Minimal legacy stub preserved only so old class names autoload without errors. */
class WebRouting extends Routing implements ResetInterface
{
	protected function build(): array { return [new \Symfony\Component\Routing\RouteCollection(), []]; }
	#[\Override]
    public function reset(): void {}
}