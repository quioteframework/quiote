<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Renderer\Renderer;
use Quiote\View\TemplateLayer;

class TRTestSampleRenderer extends Renderer
{
	public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = []): string
	{
		return '';
	}
}

class RendererTest extends UnitTestCase
{
	protected TRTestSampleRenderer $_r;

	#[\Override]
    public function setUp(): void
	{
		$this->_r = new TRTestSampleRenderer();
		$this->_r->initialize($this->getContext());
	}

	public function testGetContext(): void
	{
		$c1 = $this->getContext();
		$c2 = $this->_r->getContext();
		$this->assertSame($c1, $c2);
	}

	public function testGetStarterTemplateDefaultsToNull(): void
	{
		$this->assertNull($this->_r->getStarterTemplate());
	}

	public function testInitializeAcceptsValidScalarParameters(): void
	{
		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext(), [
			'var_name' => 'vars',
			'slots_var_name' => 'theSlots',
			'extract_vars' => true,
			'default_extension' => '.tpl',
		]);

		$this->assertSame('.tpl', $r->getDefaultExtension());
	}

	public function testInitializeRejectsNonStringVarName(): void
	{
		$r = new TRTestSampleRenderer();
		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessageMatches('/"var_name"/');
		$r->initialize($this->getContext(), ['var_name' => 123]);
	}

	public function testInitializeRejectsNonStringSlotsVarName(): void
	{
		$r = new TRTestSampleRenderer();
		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessageMatches('/"slots_var_name"/');
		$r->initialize($this->getContext(), ['slots_var_name' => []]);
	}

	public function testInitializeRejectsNonBoolExtractVars(): void
	{
		$r = new TRTestSampleRenderer();
		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessageMatches('/"extract_vars"/');
		$r->initialize($this->getContext(), ['extract_vars' => 'yes']);
	}

	public function testInitializeRejectsNonStringDefaultExtension(): void
	{
		$r = new TRTestSampleRenderer();
		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessageMatches('/"default_extension"/');
		$r->initialize($this->getContext(), ['default_extension' => 42]);
	}

	public function testInitializeRejectsNonArrayAssigns(): void
	{
		$r = new TRTestSampleRenderer();
		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessageMatches('/"assigns"/');
		$r->initialize($this->getContext(), ['assigns' => 'not-an-array']);
	}

	public function testInitializeBuildsAssignsAndMoreAssignNamesFromValidArray(): void
	{
		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext(), [
			'assigns' => [
				'request' => 'req',
				'some_unknown_thing' => 'unknownAlias',
			],
		]);

		$assignsProp = new \ReflectionProperty(\Quiote\Renderer\Renderer::class, 'assigns');
		$moreAssignNamesProp = new \ReflectionProperty(\Quiote\Renderer\Renderer::class, 'moreAssignNames');

		$assigns = $assignsProp->getValue($r);
		$moreAssignNames = $moreAssignNamesProp->getValue($r);
		self::assertIsArray($assigns);
		self::assertIsArray($moreAssignNames);

		$this->assertArrayHasKey('req', $assigns);
		$this->assertArrayHasKey('some_unknown_thing', $moreAssignNames);
		$this->assertSame('unknownAlias', $moreAssignNames['some_unknown_thing']);
	}

	/**
	 * A configuration writes a role in snake_case and the container binds it in camelCase, so the two
	 * spellings have to meet somewhere. They used to meet by accident: `getTranslationManager` matched
	 * `translation_manager` because PHP method names are case-insensitive. With the accessors gone, an
	 * assign that does not resolve becomes a template variable nobody assigns -- the template reads
	 * null on the line that held the manager, and nothing reports it.
	 */
	public function testSnakeCaseAssignsResolveToTheCamelCaseContainerRole(): void
	{
		$this->installTestTranslationManager();

		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext(), [
			'assigns' => [
				'translation_manager' => 'tm',
				'asset_registry' => 'assets',
			],
		]);

		$assigns = (new \ReflectionProperty(\Quiote\Renderer\Renderer::class, 'assigns'))->getValue($r);
		self::assertIsArray($assigns);
		$this->assertArrayHasKey('tm', $assigns, 'translation_manager must resolve, not fall through');
		$this->assertArrayHasKey('assets', $assigns, 'asset_registry must resolve, not fall through');

		$tm = $assigns['tm'];
		$assetRegistry = $assigns['assets'];
		self::assertInstanceOf(\Closure::class, $tm);
		self::assertInstanceOf(\Closure::class, $assetRegistry);
		$this->assertInstanceOf(\Quiote\Translation\TranslationManager::class, $tm());
		$this->assertInstanceOf(\Quiote\Asset\AssetRegistry::class, $assetRegistry());
	}

	/**
	 * A Context method still wins, and it is reached by the same snake_case spelling.
	 */
	public function testAContextMethodIsStillReachedBySnakeCaseName(): void
	{
		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext(), ['assigns' => ['correlation_id' => 'rid']]);

		$assigns = (new \ReflectionProperty(\Quiote\Renderer\Renderer::class, 'assigns'))->getValue($r);
		self::assertIsArray($assigns);
		$this->assertArrayHasKey('rid', $assigns);
	}
}
?>