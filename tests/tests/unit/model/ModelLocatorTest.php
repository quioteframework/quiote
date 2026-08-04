<?php

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Model\ModelClassResolver;
use Quiote\Model\ModelLocator;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;
use Sandbox\Models\ConstructorTestModel;
use Sandbox\Models\ContextTestModel;
use Sandbox\Models\ContextTestSingletonModel;
use Sandbox\Modules\ContextTest\Models\TestModel;
use Sandbox\Modules\ContextTest\Models\TestSingletonModel;

/**
 * The locator's own concerns: lifetimes, construction and the initialize() hand-off. Class-name
 * resolution is {@see ModelClassResolverTest}'s subject.
 */
#[IsolationEnvironment('testing')]
class ModelLocatorTest extends PhpUnitTestCase
{
	private function locator(?Context $context = null): ModelLocator
	{
		return new ModelLocator($context ?? Context::getInstance(), new ModelClassResolver());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetReturnsAFreshInstanceForANonSingletonModel(): void
	{
		$locator = $this->locator();

		$first = $locator->get('ContextTest');
		$second = $locator->get('ContextTest');

		$this->assertInstanceOf(ContextTestModel::class, $first);
		$this->assertNotSame($first, $second);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetSharesTheInstanceForASingletonModel(): void
	{
		$locator = $this->locator();

		$first = $locator->get('ContextTestSingleton');
		$second = $locator->get('ContextTestSingleton');

		$this->assertInstanceOf(ContextTestSingletonModel::class, $first);
		$this->assertSame($first, $second);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetResolvesAModuleModelAndBootstrapsItsModule(): void
	{
		$locator = $this->locator();

		$this->assertInstanceOf(TestModel::class, $locator->get('Test', 'ContextTest'));
		$this->assertSame(
			$locator->get('TestSingleton', 'ContextTest'),
			$locator->get('TestSingleton', 'ContextTest'),
		);
	}

	/**
	 * Every get() re-runs initialize(), so the model always holds the context that handed it out
	 * -- including a singleton returned from the cache.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetHandsTheContextToTheModel(): void
	{
		$context = Context::getInstance();

		$model = $this->locator($context)->get('ContextTest');

		$this->assertSame($context, $model->getContext());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testParametersReachTheConstructorWhenTheClassDeclaresOne(): void
	{
		$model = $this->locator()->get('ConstructorTest', null, ['first', 'second']);

		$this->assertInstanceOf(ConstructorTestModel::class, $model);
		$this->assertSame(['first', 'second'], $model->args);
	}

	/**
	 * With no parameters the constructor is called argument-less, so its defaults stand.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testTheConstructorIsCalledWithoutArgumentsWhenNoParametersAreGiven(): void
	{
		$model = $this->locator()->get('ConstructorTest');

		$this->assertInstanceOf(ConstructorTestModel::class, $model);
		$this->assertSame([], $model->args);
	}

	/**
	 * A model with no constructor must not be handed the parameters as constructor arguments --
	 * that would be a fatal error. initialize() is their only recipient there.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testParametersDoNotReachAConstructorlessModel(): void
	{
		$model = $this->locator()->get('ContextTest', null, ['ignored']);

		$this->assertInstanceOf(ContextTestModel::class, $model);
	}

	/**
	 * The locator's singleton cache is request-scoped state; reset() runs at the worker request
	 * boundary and must drop it, or request N's model data reaches request N+1.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetDropsTheSharedSingletonInstances(): void
	{
		$locator = $this->locator();
		$before = $locator->get('ContextTestSingleton');

		$locator->reset();

		$this->assertNotSame($before, $locator->get('ContextTestSingleton'));
	}

	/**
	 * reset() drops instances, not resolutions: the class names it cached are not request state.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetLeavesResolutionWorking(): void
	{
		$locator = $this->locator();
		$locator->get('ContextTest');

		$locator->reset();

		$this->assertInstanceOf(ContextTestModel::class, $locator->get('ContextTest'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetThrowsForAnUnresolvableModelName(): void
	{
		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('ThisModelDoesNotExistAnywhere');

		$this->locator()->get('ThisModelDoesNotExistAnywhere');
	}

	/**
	 * A class that resolves but is not a Model is rejected rather than returned as something the
	 * caller's Model type-hint will refuse later, further from the cause.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetThrowsWhenTheResolvedClassIsNotAModel(): void
	{
		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('does not extend');

		$this->locator()->get(\Sandbox\Models\NotAModel::class);
	}
}
