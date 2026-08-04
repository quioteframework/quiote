<?php

use Quiote\Exception\QuioteException;
use Quiote\Model\ModelClassResolver;
use Quiote\Model\ResolvedModel;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;
use Sandbox\Models\ContextTest\Child\TestModel as ChildTestModel;
use Sandbox\Models\ContextTestModel;
use Sandbox\Models\ContextTestSingletonModel;
use Sandbox\Modules\ContextTest\Models\Parent\Child\TestModel as ModuleChildTestModel;
use Sandbox\Modules\ContextTest\Models\TestModel;
use Sandbox\Modules\ContextTest\Models\TestSingletonModel;

/**
 * The resolver needs no context and touches no module bootstrapping, so every case here is a
 * direct call -- which is the point of it being its own class.
 */
#[IsolationEnvironment('testing')]
class ModelClassResolverTest extends PhpUnitTestCase
{
	/** @return array<string, array{0: string, 1: class-string, 2: bool, 3?: string}> */
	public static function dataResolve(): array
	{
		return [
			'global model' => ['ContextTest', ContextTestModel::class, false],
			'global singleton model' => ['ContextTestSingleton', ContextTestSingletonModel::class, true],
			'global model in a child path' => ['ContextTest.Child.Test', ChildTestModel::class, false],
			'module model' => ['Test', TestModel::class, false, 'ContextTest'],
			'module singleton model' => ['TestSingleton', TestSingletonModel::class, true, 'ContextTest'],
			'module model in a child path' => ['Parent.Child.Test', ModuleChildTestModel::class, false, 'ContextTest'],
			'fully qualified class name' => [ContextTestModel::class, ContextTestModel::class, false],
		];
	}

	/** @param class-string $expectedClass */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataResolve')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResolveFindsTheClassAndItsSingletonFlag(
		string $modelName,
		string $expectedClass,
		bool $isSingleton,
		?string $moduleName = null,
	): void {
		$resolved = (new ModelClassResolver())->resolve($modelName, $moduleName);

		$this->assertInstanceOf(ResolvedModel::class, $resolved);
		$this->assertSame($expectedClass, $resolved->class);
		$this->assertSame($isSingleton, $resolved->isSingleton);
	}

	/**
	 * A name that already carries the suffix must not acquire a second one.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResolveAcceptsAQualifiedNameThatAlreadyEndsInModel(): void
	{
		$resolved = (new ModelClassResolver())->resolve(ContextTestModel::class);

		$this->assertSame(ContextTestModel::class, $resolved->class);
	}

	/**
	 * A resolution is a pure function of (model name, module name), so the second call answers
	 * the identical object rather than repeating the class_exists chain and the reflection probe.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResolveCachesPerModelAndModule(): void
	{
		$resolver = new ModelClassResolver();

		$first = $resolver->resolve('ContextTest');
		$this->assertSame($first, $resolver->resolve('ContextTest'));

		// Same model name, different module: a distinct cache key, a distinct class.
		$moduleScoped = $resolver->resolve('Test', 'ContextTest');
		$this->assertSame(TestModel::class, $moduleScoped->class);
		$this->assertNotSame($first, $moduleScoped);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testClearCacheForcesAFreshResolution(): void
	{
		$resolver = new ModelClassResolver();
		$first = $resolver->resolve('ContextTest');

		$resolver->clearCache();

		$second = $resolver->resolve('ContextTest');
		$this->assertNotSame($first, $second, 'the cleared cache is not consulted');
		$this->assertSame($first->class, $second->class);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResolveThrowsForAnUnknownModelName(): void
	{
		$this->expectException(QuioteException::class);
		$this->expectExceptionMessage('ThisModelDoesNotExistAnywhere');

		(new ModelClassResolver())->resolve('ThisModelDoesNotExistAnywhere');
	}

	/**
	 * A failed resolution must leave nothing behind: a poisoned entry would make a model that
	 * becomes loadable later permanently unresolvable for the rest of the worker's life.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAFailedResolutionDoesNotPoisonTheCache(): void
	{
		$resolver = new ModelClassResolver();

		try {
			$resolver->resolve('ContextTestSingleton', 'NoSuchModuleForThisTest');
			$this->fail('Expected the unresolvable module-scoped name to throw.');
		} catch (QuioteException) {
			// expected
		}

		// The same name resolves once the module is right, which it could not do from a
		// cached failure.
		$this->assertSame(
			ContextTestSingletonModel::class,
			$resolver->resolve('ContextTestSingleton')->class,
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResolveReportsWhetherTheClassDeclaresAConstructor(): void
	{
		$resolver = new ModelClassResolver();

		$this->assertFalse(
			$resolver->resolve('ContextTest')->hasConstructor,
			'the sandbox model declares no constructor',
		);
		$this->assertTrue($resolver->resolve('ConstructorTest')->hasConstructor);
	}
}
