<?php

use Quiote\Config\Config;
use Quiote\Routing\AttributeRouting;
use Quiote\Testing\PhpUnitTestCase;

class RouteCollectionBuilderTest extends PhpUnitTestCase
{
	private function makeAttributeRouting(): AttributeRouting
	{
		$moduleDir = Config::getString('core.module_dir');
		return new class($moduleDir) extends AttributeRouting {
			public function __construct(private readonly string $moduleDir)
			{
				parent::__construct();
			}

			#[\Override]
			protected function moduleDirs(): ?iterable
			{
				return [$this->moduleDir];
			}
		};
	}

	public function testBuiltCollectionMatchesThroughTheRealUrlMatcher()
	{
		$routing = $this->makeAttributeRouting();

		$attributes = $routing->match('/attr-routing');
		$this->assertSame('AttrRouting', $attributes['_module']);
		$this->assertSame('List', $attributes['_action']);
		$this->assertSame('attr_routing.list', $attributes['_route']);

		$attributes = $routing->match('/attr-routing/42');
		$this->assertSame('AttrRouting', $attributes['_module']);
		$this->assertSame('View', $attributes['_action']);
		$this->assertSame('42', $attributes['id']);
		$this->assertSame('html', $attributes['_output_type']);
	}

	public function testMethodConstrainedRouteRejectsWrongMethod()
	{
		$routing = $this->makeAttributeRouting();
		$routing->getRequestContext()->setMethod('GET');

		$this->expectException(\Symfony\Component\Routing\Exception\MethodNotAllowedException::class);
		$routing->match('/attr-routing/new');
	}

	public function testMethodConstrainedRouteAcceptsDeclaredMethod()
	{
		$routing = $this->makeAttributeRouting();
		$routing->getRequestContext()->setMethod('POST');

		$attributes = $routing->match('/attr-routing/new');
		$this->assertSame('AttrRouting', $attributes['_module']);
		$this->assertSame('Index.Add', $attributes['_action']);
	}

	public function testUnknownPathIsNotResourceFound()
	{
		$routing = $this->makeAttributeRouting();

		$this->expectException(\Symfony\Component\Routing\Exception\ResourceNotFoundException::class);
		$routing->match('/no-such-route');
	}

	public function testGenReversesRouteToOriginalPath()
	{
		$routing = $this->makeAttributeRouting();

		$this->assertSame('/attr-routing/42', $routing->gen('attr_routing.view', ['id' => 42]));
	}
}
