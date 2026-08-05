<?php

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\ContextRegistry;
use Quiote\Exception\QuioteException;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;
use Sandbox\Models\ContextTestSingletonModel;

#[IsolationEnvironment('testing')]
class ContextRegistryTest extends PhpUnitTestCase
{
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetReturnsOneInitializedContextPerProfile(): void
	{
		$registry = new ContextRegistry();

		$first = $registry->get('registry_a');
		$second = $registry->get('registry_a');

		$this->assertInstanceOf(Context::class, $first);
		$this->assertSame($first, $second);
		$this->assertSame('registry_a', $first->getName());
		// Reaching a component proves initialize() ran; an uninitialized context throws here.
		$this->assertInstanceOf(\Quiote\Controller\Controller::class, $first->getController());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetGivesDifferentProfilesDifferentContexts(): void
	{
		$registry = new ContextRegistry();

		$this->assertNotSame($registry->get('registry_a'), $registry->get('registry_b'));
	}

	/**
	 * A profile is a configuration identity, so casing must not split one into two contexts.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testProfileNamesAreMatchedCaseInsensitively(): void
	{
		$registry = new ContextRegistry();

		$this->assertSame($registry->get('MixedCase'), $registry->get('mixedcase'));
		$this->assertSame('mixedcase', $registry->get('MIXEDCASE')->getName());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetWithNoProfileUsesTheConfiguredDefault(): void
	{
		$registry = new ContextRegistry();
		$default = Config::getString('core.default_context');

		$this->assertSame($registry->get($default), $registry->get());
	}

	/**
	 * has() answers about what exists. Creating a context to answer it would defeat the point --
	 * the request-boundary reset uses exactly this distinction.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testHasDoesNotCreateAContext(): void
	{
		$registry = new ContextRegistry();

		$this->assertFalse($registry->has('registry_absent'));
		$this->assertSame([], $registry->names());

		$registry->get('registry_absent');

		$this->assertTrue($registry->has('registry_absent'));
		$this->assertTrue($registry->has('REGISTRY_ABSENT'), 'has() normalizes like get()');
		$this->assertSame(['registry_absent'], $registry->names());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testClearForgetsEveryContext(): void
	{
		$registry = new ContextRegistry();
		$before = $registry->get('registry_a');

		$registry->clear();

		$this->assertSame([], $registry->names());
		$this->assertNotSame($before, $registry->get('registry_a'));
	}

	/**
	 * The invariant the old static map only asserted in a comment: every live context is reset,
	 * not just the one that served the request. A context left holding request-scoped state --
	 * its session bag, its user -- is a cross-user leak, not stale data.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetAllResetsEveryLiveContextNotOnlyTheNamedOne(): void
	{
		$registry = new ContextRegistry();
		$a = $registry->get('registry_a');
		$b = $registry->get('registry_b');

		// A shared singleton model is request-scoped state reset() must drop, so it is the
		// observable for "this context was reset".
		$modelA = $a->getModelLocator()->get(ContextTestSingletonModel::class);
		$modelB = $b->getModelLocator()->get(ContextTestSingletonModel::class);

		$registry->resetAll('registry_a');

		$this->assertNotSame($modelA, $a->getModelLocator()->get(ContextTestSingletonModel::class));
		$this->assertNotSame($modelB, $b->getModelLocator()->get(ContextTestSingletonModel::class));
	}

	/**
	 * reset() clears request-scoped state; it does not tear the context down and rebuild it.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetAllKeepsTheContextInstancesThemselves(): void
	{
		$registry = new ContextRegistry();
		$a = $registry->get('registry_a');
		$b = $registry->get('registry_b');

		$registry->resetAll();

		$this->assertSame($a, $registry->get('registry_a'));
		$this->assertSame($b, $registry->get('registry_b'));
		$this->assertSame(['registry_a', 'registry_b'], $registry->names());
	}

	/**
	 * Naming a profile that was never instantiated must not bring it into being at the request
	 * boundary just to reset it.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetAllForAnUninstantiatedProfileCreatesNothing(): void
	{
		$registry = new ContextRegistry();

		$registry->resetAll('never_instantiated_profile');

		$this->assertSame([], $registry->names());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetAllOnAnEmptyRegistryIsANoOp(): void
	{
		$registry = new ContextRegistry();

		$registry->resetAll();

		$this->assertSame([], $registry->names());
	}

	/**
	 * The fallback is how Context::getInstance() keeps its late-static-binding behaviour, and it
	 * only applies when core.context_implementation is unset.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testTheFallbackClassIsUsedWhenNoImplementationIsConfigured(): void
	{
		$this->assertNull(Config::getNullableString('core.context_implementation'));

		$context = (new ContextRegistry())->get('registry_fallback', SubclassedContextForRegistryTest::class);

		$this->assertInstanceOf(SubclassedContextForRegistryTest::class, $context);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAConfiguredImplementationWinsOverTheFallback(): void
	{
		Config::set('core.context_implementation', SubclassedContextForRegistryTest::class);

		$context = (new ContextRegistry())->get('registry_configured', Context::class);

		$this->assertInstanceOf(SubclassedContextForRegistryTest::class, $context);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAnImplementationThatIsNotAContextIsRejected(): void
	{
		Config::set('core.context_implementation', \stdClass::class);

		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('does not extend');

		(new ContextRegistry())->get('registry_bad');
	}

	/**
	 * Context::getInstance() must answer from the shared registry, or the framework's static
	 * callers and anything that injected the registry would see two different contexts for the
	 * same profile.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testTheSharedRegistryBacksContextGetInstance(): void
	{
		$shared = ContextRegistry::shared();

		$this->assertSame($shared, ContextRegistry::shared(), 'the shared registry is built once');
		$this->assertSame(Context::getInstance('registry_shared'), $shared->get('registry_shared'));
		$this->assertTrue($shared->has('registry_shared'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testTheContainerResolvesTheSharedRegistry(): void
	{
		$container = Context::getInstance()->getContainer();

		$this->assertSame(ContextRegistry::shared(), $container->get(ContextRegistry::class));
		$this->assertSame(ContextRegistry::shared(), $container->get('contexts'));
	}

	/**
	 * Context::resetWorkerState() is the worker request boundary's entry point and must reach the
	 * same instances Context::getInstance() hands out.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetWorkerStateResetsTheSharedRegistrysContexts(): void
	{
		$context = Context::getInstance('registry_worker');
		$before = $context->getModelLocator()->get(ContextTestSingletonModel::class);

		Context::resetWorkerState('registry_worker');

		$this->assertNotSame($before, $context->getModelLocator()->get(ContextTestSingletonModel::class));
		$this->assertSame($context, Context::getInstance('registry_worker'));
	}
}

/**
 * Stands in for an application's own context subclass, which the registry has to build knowing
 * only the profile name.
 */
class SubclassedContextForRegistryTest extends Context
{
}
