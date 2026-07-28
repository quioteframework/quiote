<?php

use Quiote\Routing\Compiler\RouteCollectionBuilder;
use Quiote\Routing\Compiler\RouteCollectionIntrospector;
use Quiote\Routing\Compiler\RouteDefinition;
use Quiote\Routing\Compiler\RoutePlan;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

/**
 * Lifting a live RouteCollection back into the routing IR -- the inverse of
 * RouteCollectionBuilder, so anything describing every route the app serves
 * (OpenAPI generation) sees file-declared and #[Route]-declared routes in one
 * shape.
 */
final class RouteCollectionIntrospectorTest extends PhpUnitTestCase
{
	public function testReadsBackModuleActionAndOutputTypeFromTheRouteDefaults(): void
	{
		$collection = new RouteCollection();
		$collection->add('orders.view', new SymfonyRoute(
			'/orders/{id}',
			['_module' => 'Orders', '_action' => 'View', '_output_type' => 'json', 'page' => 1],
			['id' => '\d+'],
			[],
			'api.example.test',
			[],
			['GET', 'HEAD'],
			"request.headers.get('X-Api') == '1'",
		));

		$definitions = (new RouteCollectionIntrospector())->toDefinitions($collection, 'test');

		$this->assertCount(1, $definitions);
		$definition = $definitions[0];
		$this->assertSame('orders.view', $definition->name);
		$this->assertSame('/orders/{id}', $definition->path);
		$this->assertSame('Orders', $definition->module);
		$this->assertSame('View', $definition->action);
		$this->assertSame('json', $definition->outputType);
		$this->assertSame(['GET', 'HEAD'], $definition->methods);
		$this->assertSame(['id' => '\d+'], $definition->requirements);
		$this->assertSame('api.example.test', $definition->host);
		$this->assertSame("request.headers.get('X-Api') == '1'", $definition->condition);
		$this->assertSame('test', $definition->sourceRef);
	}

	public function testTheDispatchDefaultsAreNotRepeatedInTheReportedDefaults(): void
	{
		$collection = new RouteCollection();
		$collection->add('orders.view', new SymfonyRoute(
			'/orders/{id}',
			['_module' => 'Orders', '_action' => 'View', '_output_type' => 'json', 'page' => 1],
		));

		$definition = (new RouteCollectionIntrospector())->toDefinitions($collection)[0];

		$this->assertSame(['page' => 1], $definition->defaults);
	}

	public function testAnEmptyHostConditionAndOutputTypeComeBackAsNull(): void
	{
		$collection = new RouteCollection();
		$collection->add('bare', new SymfonyRoute('/bare', ['_module' => 'X', '_action' => 'Y']));

		$definition = (new RouteCollectionIntrospector())->toDefinitions($collection)[0];

		$this->assertNull($definition->host);
		$this->assertNull($definition->condition);
		$this->assertNull($definition->outputType);
		$this->assertSame([], $definition->methods);
	}

	public function testARouteWithNoDispatchDefaultsYieldsEmptyModuleAndAction(): void
	{
		// A collection entry that resolves to no action at all (a redirect
		// entry, say) must not be guessed at.
		$collection = new RouteCollection();
		$collection->add('redirect', new SymfonyRoute('/old'));

		$definition = (new RouteCollectionIntrospector())->toDefinitions($collection)[0];

		$this->assertSame('', $definition->module);
		$this->assertSame('', $definition->action);
	}

	public function testAnEmptyCollectionYieldsNoDefinitions(): void
	{
		$this->assertSame([], (new RouteCollectionIntrospector())->toDefinitions(new RouteCollection()));
	}

	public function testRoundTripsWhatRouteCollectionBuilderProduced(): void
	{
		$source = new RouteDefinition(
			'orders.view',
			'/orders/{id}',
			'Orders',
			'View',
			['GET'],
			['page' => 1],
			['id' => '\d+'],
			'api.example.test',
			null,
			0,
			'json',
			['gen_path' => '/orders/{id}', 'cut' => false, 'path' => '/orders/{id}'],
			'source.php',
		);

		[$collection] = (new RouteCollectionBuilder())->build(new RoutePlan([$source], 'test'));
		$roundTripped = (new RouteCollectionIntrospector())->toDefinitions($collection, 'source.php')[0];

		foreach (['name', 'path', 'module', 'action', 'methods', 'defaults', 'requirements', 'host', 'condition', 'outputType', 'meta', 'sourceRef'] as $property) {
			$this->assertSame($source->$property, $roundTripped->$property, $property . ' survives the round trip');
		}
	}
}
