<?php

declare(strict_types=1);

namespace WorkerProbe\Routing;

use Quiote\Routing\Routing;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class ProbeRouting extends Routing
{
	protected function build(): array
	{
		$routes = new RouteCollection();
		$meta = [];

		foreach ([
			'index' => ['/', 'Index'],
			'echoback' => ['/echoback', 'Echoback'],
			'session' => ['/session', 'Session'],
			'stray' => ['/stray', 'Stray'],
			'stream' => ['/stream', 'Stream'],
			'cookies' => ['/cookies', 'Cookies'],
			'boom' => ['/boom', 'Boom'],
			'identity' => ['/identity', 'Identity'],
			'login' => ['/login', 'Login'],
			'logout' => ['/logout', 'Logout'],
			'quiet' => ['/quiet', 'Quiet'],
		] as $name => [$path, $action]) {
			$routes->add($name, new Route($path, ['_module' => 'Probe', '_action' => $action]));
			$meta[$name] = ['gen_path' => $path, 'path' => $path, 'cut' => false];
		}

		return [$routes, $meta];
	}

	#[\Override]
	public function exportRoutes(): array
	{
		return [$this->getRouteCollection(), $this->getMeta()];
	}
}
