<?php

use Quiote\Renderer\Renderer;
use Quiote\Testing\UnitTestCase;
use Quiote\View\TemplateLayer;

/**
 * Records the attributes it was handed so a test can assert on what
 * TemplateLayer::execute() merged into them.
 */
class AttributeCapturingRenderer extends Renderer
{
	/** @var array<string, mixed> */
	public array $captured = [];

	/**
	 * @param      array<string, mixed> $attributes
	 * @param      array<string, mixed> $slots
	 * @param      array<int|string, mixed> $moreAssigns
	 */
	public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
	{
		$this->captured = $attributes;
		return '';
	}
}

class MergeTestTemplateLayer extends TemplateLayer
{
	public function getResourceStreamIdentifier()
	{
		return null;
	}
}

/**
 * execute() merges the layer's own parameters into the caller's $attributes as
 * defaults, adding the moduleName/actionName aliases templates rely on. These
 * cover the merge semantics directly, since the merge is now done in place
 * instead of via an intermediate normalized copy of the parameters.
 */
class TemplateLayerParameterMergeTest extends UnitTestCase
{
	/**
	 * @param      array<string, mixed> $parameters The layer's configured parameters.
	 */
	private function layer(array $parameters): MergeTestTemplateLayer
	{
		$layer = new MergeTestTemplateLayer($parameters);
		$layer->initialize($this->getContext(), []);
		return $layer;
	}

	public function testLayerParametersLandInTheAttributesWithAliases(): void
	{
		$renderer = new AttributeCapturingRenderer();
		$layer = $this->layer(['module' => 'Orders', 'template' => 'Index', 'extra' => 7]);
		$attributes = [];

		$layer->execute($renderer, $attributes);

		$this->assertSame('Orders', $renderer->captured['module']);
		$this->assertSame('Index', $renderer->captured['template']);
		$this->assertSame(7, $renderer->captured['extra']);
		$this->assertSame('Orders', $renderer->captured['moduleName'], 'moduleName aliases the layer module');
		$this->assertSame('Index', $renderer->captured['actionName'], 'actionName aliases the layer template');
	}

	public function testCallerSuppliedAttributesWinOverLayerParameters(): void
	{
		$renderer = new AttributeCapturingRenderer();
		$layer = $this->layer(['module' => 'Orders', 'template' => 'Index']);
		$attributes = ['module' => 'CallerModule', 'moduleName' => 'CallerModuleName'];

		$layer->execute($renderer, $attributes);

		$this->assertSame('CallerModule', $renderer->captured['module']);
		$this->assertSame('CallerModuleName', $renderer->captured['moduleName']);
		// Not overridden by the caller, so the layer still supplies these.
		$this->assertSame('Index', $renderer->captured['template']);
		$this->assertSame('Index', $renderer->captured['actionName']);
	}

	/**
	 * The alias derives from the layer's own module, not from a module the
	 * caller happened to pass -- the caller only wins for the key it actually
	 * set.
	 */
	public function testAliasDerivesFromTheLayerParameterNotTheCallerOverride(): void
	{
		$renderer = new AttributeCapturingRenderer();
		$layer = $this->layer(['module' => 'Orders', 'template' => 'Index']);
		$attributes = ['module' => 'CallerModule'];

		$layer->execute($renderer, $attributes);

		$this->assertSame('CallerModule', $renderer->captured['module']);
		$this->assertSame('Orders', $renderer->captured['moduleName']);
	}

	/**
	 * A layer that declares moduleName/actionName itself keeps them; the
	 * aliases never overwrite an explicit value.
	 */
	public function testExplicitLayerAliasesAreNotOverwritten(): void
	{
		$renderer = new AttributeCapturingRenderer();
		$layer = $this->layer([
			'module' => 'Orders',
			'template' => 'Index',
			'moduleName' => 'ExplicitModuleName',
			'actionName' => 'ExplicitActionName',
		]);
		$attributes = [];

		$layer->execute($renderer, $attributes);

		$this->assertSame('ExplicitModuleName', $renderer->captured['moduleName']);
		$this->assertSame('ExplicitActionName', $renderer->captured['actionName']);
	}

	/**
	 * The constructor defaults module/template to null; a null is not a value
	 * to alias from, and an attribute key the caller set to null still counts
	 * as set (matching the array-union semantics this merge replaced).
	 */
	public function testNullParametersProduceNoAliasesAndNullAttributesAreKept(): void
	{
		$renderer = new AttributeCapturingRenderer();
		$layer = $this->layer([]);
		$attributes = ['extra' => null];

		$layer->execute($renderer, $attributes);

		$this->assertArrayNotHasKey('moduleName', $renderer->captured);
		$this->assertArrayNotHasKey('actionName', $renderer->captured);
		$this->assertArrayHasKey('module', $renderer->captured);
		$this->assertNull($renderer->captured['module']);
		$this->assertArrayHasKey('extra', $renderer->captured);
		$this->assertNull($renderer->captured['extra']);
	}

	/**
	 * execute() takes $attributes by reference and must keep merging into that
	 * same array, since templates receive it by reference as $t.
	 */
	public function testAttributesAreMergedInPlaceByReference(): void
	{
		$renderer = new AttributeCapturingRenderer();
		$layer = $this->layer(['module' => 'Orders', 'template' => 'Index']);
		$attributes = [];

		$layer->execute($renderer, $attributes);

		$this->assertSame('Orders', $attributes['module'], 'the caller\'s own array must see the merge');
		$this->assertSame('Orders', $attributes['moduleName']);
		$this->assertSame('Index', $attributes['actionName']);
	}
}
