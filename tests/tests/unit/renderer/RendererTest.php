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

	/**
	 * Without extraction the template and the slots go into the rendering
	 * scope under their own names, so sharing one name would mean the slots
	 * silently overwrite the template variable.
	 */
	public function testInitializeRejectsIdenticalTemplateAndSlotsVariableNames(): void
	{
		$r = new TRTestSampleRenderer();

		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessage('cannot be identical');

		$r->initialize($this->getContext(), ['var_name' => 'same', 'slots_var_name' => 'same']);
	}

	/** With extraction on there is no container variable to collide, so the same name is fine. */
	public function testIdenticalVariableNamesAreAcceptedWhenVariablesAreExtracted(): void
	{
		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext(), [
			'var_name' => 'same',
			'slots_var_name' => 'same',
			'extract_vars' => true,
		]);

		$this->assertTrue($this->readProperty($r, 'extractVars'));
		$this->assertSame('same', $this->readProperty($r, 'varName'));
	}

	private function readProperty(Renderer $renderer, string $name): mixed
	{
		return (new \ReflectionProperty(Renderer::class, $name))->getValue($renderer);
	}

	/** A null name is how config says "resolve this, but do not assign it". */
	public function testAnAssignWithANullNameIsSkipped(): void
	{
		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext(), ['assigns' => ['correlation_id' => null]]);

		$assigns = (new \ReflectionProperty(\Quiote\Renderer\Renderer::class, 'assigns'))->getValue($r);
		self::assertIsArray($assigns);
		$this->assertSame([], $assigns);
	}

	public function testAnAssignNameThatIsNeitherStringNorIntIsRejected(): void
	{
		$r = new TRTestSampleRenderer();

		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessage('An assign name must be a string or an integer');

		$r->initialize($this->getContext(), ['assigns' => ['correlation_id' => ['nested']]]);
	}

	/**
	 * getContext() is documented to throw rather than answer null, so a
	 * renderer used before initialize() says so instead of failing later with
	 * a null dereference somewhere in a template.
	 */
	public function testGetContextThrowsBeforeInitialization(): void
	{
		$r = new TRTestSampleRenderer();

		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessage('has not been initialized');

		$r->getContext();
	}

	/** Without a context there is nothing to resolve an assign against. */
	public function testAssignsResolveToNothingWithoutAContext(): void
	{
		$r = new TRTestSampleRenderer();
		$resolverFor = new \ReflectionMethod(\Quiote\Renderer\Renderer::class, 'resolverFor');

		$this->assertNull($resolverFor->invoke($r, 'correlation_id'));
	}

	// --- serialization -----------------------------------------------------

	/**
	 * A Context is per-process and cannot be serialized, so it is dropped and
	 * restored by name -- a cached renderer that carried one would rehydrate a
	 * second, detached context.
	 */
	public function testSerializationDropsTheContextAndRestoresItByName(): void
	{
		$restored = unserialize(serialize($this->_r));

		$this->assertInstanceOf(TRTestSampleRenderer::class, $restored);
		$this->assertSame($this->getContext(), $restored->getContext());
	}

	public function testTheSerializedFormCarriesNoContextInstance(): void
	{
		$serialized = serialize($this->_r);

		$this->assertStringNotContainsString('Quiote\Context', $serialized);
		$this->assertStringContainsString($this->getContext()->getName(), $serialized);
	}

	// --- "more assigns" ----------------------------------------------------

	/**
	 * @param array<int|string, mixed> $moreAssigns
	 * @param array<int|string, mixed> $moreAssignNames
	 * @return array<int|string, mixed>
	 */
	private function buildMoreAssigns(array &$moreAssigns, array $moreAssignNames): array
	{
		$method = new \ReflectionMethod(\Quiote\Renderer\Renderer::class, 'buildMoreAssigns');

		/** @var array<int|string, mixed> $result */
		$result = $method->invokeArgs(null, [&$moreAssigns, $moreAssignNames]);

		return $result;
	}

	public function testMoreAssignsAreRenamedByTheConfiguredMap(): void
	{
		$moreAssigns = ['routing' => 'r-value', 'user' => 'u-value'];

		$built = $this->buildMoreAssigns($moreAssigns, ['routing' => 'ro']);

		$this->assertSame(['ro' => 'r-value', 'user' => 'u-value'], $built);
	}

	/** A null mapped name means "do not pass this one to the template". */
	public function testAMoreAssignMappedToNullIsDropped(): void
	{
		$moreAssigns = ['routing' => 'r-value', 'user' => 'u-value'];

		$built = $this->buildMoreAssigns($moreAssigns, ['routing' => null]);

		$this->assertSame(['user' => 'u-value'], $built);
	}

	public function testAMoreAssignNameThatIsNeitherStringNorIntIsRejected(): void
	{
		$moreAssigns = ['routing' => 'r-value'];

		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessage('A "more assign" name must be a string or an integer');

		$this->buildMoreAssigns($moreAssigns, ['routing' => ['nested']]);
	}

	/**
	 * The values are passed through by reference, so a template mutating one
	 * writes back to what the caller handed over.
	 */
	public function testMoreAssignsKeepTheirReferenceToTheOriginalValues(): void
	{
		$moreAssigns = ['routing' => 'original'];

		$built = $this->buildMoreAssigns($moreAssigns, []);
		$built['routing'] = 'mutated';

		$this->assertSame('mutated', $moreAssigns['routing']);
	}

	// --- reuse -------------------------------------------------------------

	/**
	 * A pooled renderer is reset between renderings, so nothing configured for
	 * one view can reach the next.
	 */
	public function testResetReturnsTheRendererToItsPostConstructionState(): void
	{
		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext(), [
			'var_name' => 'vars',
			'slots_var_name' => 'theSlots',
			'extract_vars' => true,
			'default_extension' => '.tpl',
			'assigns' => ['correlation_id' => 'rid'],
		]);

		$r->reset();

		$fresh = new TRTestSampleRenderer();
		foreach (['varName', 'slotsVarName', 'extractVars', 'defaultExtension'] as $property) {
			$this->assertSame(
				$this->readProperty($fresh, $property),
				$this->readProperty($r, $property),
				$property . ' must be back to its post-construction value',
			);
		}

		$assigns = (new \ReflectionProperty(\Quiote\Renderer\Renderer::class, 'assigns'))->getValue($r);
		$this->assertSame([], $assigns);
	}

	public function testResetDropsTheContextSoTheRendererMustBeInitializedAgain(): void
	{
		$r = new TRTestSampleRenderer();
		$r->initialize($this->getContext());

		$r->reset();

		$this->expectException(\Quiote\Exception\QuioteException::class);
		$r->getContext();
	}
}
?>