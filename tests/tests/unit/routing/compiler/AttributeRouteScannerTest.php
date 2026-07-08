<?php

use Quiote\Config\Config;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Testing\PhpUnitTestCase;

class AttributeRouteScannerTest extends PhpUnitTestCase
{
	public function testScanDiscoversFlatParameterizedAndNestedRoutes(): void
	{
		$scanner = new AttributeRouteScanner();
		$plan = $scanner->scan([Config::getString('core.module_dir')]);

		$byName = [];
		foreach ($plan->routes as $route) {
			$byName[$route->name] = $route;
		}

		$this->assertArrayHasKey('attr_routing.list', $byName);
		$this->assertSame('/attr-routing', $byName['attr_routing.list']->path);
		$this->assertSame('AttrRouting', $byName['attr_routing.list']->module);
		$this->assertSame('List', $byName['attr_routing.list']->action);
		$this->assertSame(['GET'], $byName['attr_routing.list']->methods);

		$this->assertArrayHasKey('attr_routing.view', $byName);
		$this->assertSame('/attr-routing/{id}', $byName['attr_routing.view']->path);
		$this->assertSame(['id' => '\\d+'], $byName['attr_routing.view']->requirements);
		$this->assertSame('html', $byName['attr_routing.view']->outputType);

		$this->assertArrayHasKey('attr_routing.add', $byName);
		$this->assertSame('Index.Add', $byName['attr_routing.add']->action);
		$this->assertSame(['POST'], $byName['attr_routing.add']->methods);

		$this->assertEmpty($scanner->getDiagnostics());
	}

	public function testScanIgnoresActionsWithoutRouteAttribute(): void
	{
		$scanner = new AttributeRouteScanner();
		$plan = $scanner->scan([Config::getString('core.module_dir')]);

		foreach ($plan->routes as $route) {
			$this->assertNotSame('Unrouted', $route->action);
		}
	}

	public function testScanFlagsDuplicateRouteNamesAsErrors(): void
	{
		$scanner = new AttributeRouteScanner();
		$scanner->scan([dirname(__DIR__, 4) . '/fixtures/RoutingDup/Modules']);

		$diagnostics = $scanner->getDiagnostics();
		$this->assertNotEmpty($diagnostics);
		$this->assertSame(AttributeRouteScanner::CODE_DUPLICATE_ROUTE_NAME, $diagnostics[0]->code);
		$this->assertSame(\Quiote\Support\Compiler\Diagnostic::SEVERITY_ERROR, $diagnostics[0]->severity);
	}

	public function testScanFlagsDuplicatePathAndMethodAsWarning(): void
	{
		$scanner = new AttributeRouteScanner();
		$scanner->scan([dirname(__DIR__, 4) . '/fixtures/RoutingDupPath/Modules']);

		$diagnostics = $scanner->getDiagnostics();
		$this->assertNotEmpty($diagnostics);
		$this->assertSame(AttributeRouteScanner::CODE_DUPLICATE_ROUTE_PATH, $diagnostics[0]->code);
		$this->assertSame(\Quiote\Support\Compiler\Diagnostic::SEVERITY_WARNING, $diagnostics[0]->severity);
	}
}
