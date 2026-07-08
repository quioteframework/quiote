<?php

use Quiote\Config\Config;
use Quiote\Routing\AttributeRoutes;
use Quiote\Routing\Routing;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Attribute routing (Quiote\Routing\AttributeRouting) and programmatic
 * routing (a plain Routing::build(), the style samples/app's AppRouting
 * uses) are not alternatives you must pick one of -- AttributeRoutes::
 * mergeInto() lets a single Routing subclass declare some routes by hand
 * and pull in #[Route]-attributed actions for the rest, in one
 * RouteCollection. This is exactly the shape samples/app now uses.
 */
class AttributeRoutesMergeTest extends PhpUnitTestCase
{
	private function makeMixedRouting(): Routing
	{
		$moduleDir = Config::getString('core.module_dir');

		return new class($moduleDir) extends Routing {
			public function __construct(private readonly string $moduleDir)
			{
				parent::__construct();
			}

			protected function build(): array
			{
				$routes = new RouteCollection();
				$meta = [];

				// Hand-written, file-based route -- same shape as AppRouting::build().
				$routes->add('home', new Route('/', ['_module' => 'Default', '_action' => 'Index']));
				$meta['home'] = ['gen_path' => '/', 'path' => '/', 'cut' => false];

				// Pulled in from #[Route] attributes on action classes.
				AttributeRoutes::mergeInto($routes, $meta, [$this->moduleDir]);

				return [$routes, $meta];
			}
		};
	}

	public function testHandWrittenAndAttributeRoutesCoexistInOneCollection(): void
	{
		$routing = $this->makeMixedRouting();

		$attributes = $routing->match('/');
		$this->assertSame('Default', $attributes['_module']);
		$this->assertSame('Index', $attributes['_action']);
		$this->assertSame('home', $attributes['_route']);

		$attributes = $routing->match('/attr-routing/42');
		$this->assertSame('AttrRouting', $attributes['_module']);
		$this->assertSame('View', $attributes['_action']);
		$this->assertSame('42', $attributes['id']);
	}

	public function testGenWorksForBothRouteSources(): void
	{
		$routing = $this->makeMixedRouting();

		$this->assertSame('/', $routing->gen('home'));
		$this->assertSame('/attr-routing/42', $routing->gen('attr_routing.view', ['id' => 42]));
	}

	public function testMergeIntoSurfacesScannerDiagnostics(): void
	{
		$routes = new RouteCollection();
		$meta = [];

		$diagnostics = AttributeRoutes::mergeInto($routes, $meta, [dirname(__DIR__, 4) . '/fixtures/RoutingDup/Modules']);

		$this->assertNotEmpty($diagnostics);
		$this->assertTrue($routes->get('dup.same') !== null);
	}
}
