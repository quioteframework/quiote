<?php
namespace SampleApp\Routing;

use Quiote\Routing\AttributeRoutes;
use Quiote\Routing\Routing;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Plain PHP routing -- routing.xml has no
 * working config handler today, so a Routing subclass building the
 * RouteCollection directly is the supported way to declare routes.
 *
 * Index/About/Boom are declared here by hand; Contact is declared via a
 * #[Route] attribute on ContactAction instead and pulled into this same
 * RouteCollection by AttributeRoutes::mergeInto() -- the two styles are not
 * mutually exclusive.
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

		AttributeRoutes::mergeInto($routes, $meta);

		return [$routes, $meta];
	}

	#[\Override]
	public function exportRoutes(): array
	{
		return [$this->getRouteCollection(), $this->getMeta()];
	}
}
