<?php
namespace SampleApp\Routing;

use Quiote\Routing\Routing;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Plain PHP routing -- see docs/ROUTING_AND_CLI_PLAN.md: routing.xml has no
 * working config handler today, so a Routing subclass building the
 * RouteCollection directly is the supported way to declare routes.
 */
final class AppRouting extends Routing
{
	protected function build(): array
	{
		$routes = new RouteCollection();
		$meta = [];

		$routes->add('index', new Route('/', ['_module' => 'Default', '_action' => 'Index']));
		$meta['index'] = ['gen_path' => '/', 'path' => '/', 'cut' => false];

		$routes->add('about', new Route('/about', ['_module' => 'Default', '_action' => 'About']));
		$meta['about'] = ['gen_path' => '/about', 'path' => '/about', 'cut' => false];

		$routes->add('boom', new Route('/boom', ['_module' => 'Default', '_action' => 'Boom']));
		$meta['boom'] = ['gen_path' => '/boom', 'path' => '/boom', 'cut' => false];

		return [$routes, $meta];
	}

	#[\Override]
	public function exportRoutes(): array
	{
		return [$this->getRouteCollection(), $this->getMeta()];
	}
}
