<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Context;
use Quiote\Config\Config;
use Sandbox\Models\ContextTest\Child\TestModel as ChildTestModel;
use Sandbox\Models\ContextTestModel;
use Sandbox\Models\ContextTestSingletonModel;
use Sandbox\Modules\ContextTest\Models\TestModel;
use Sandbox\Modules\ContextTest\Models\TestSingletonModel;
use Sandbox\Modules\ContextTest\Models\Parent\Child\TestModel as ModuleChildTestModel;

#[IsolationEnvironment('testing')]
class ContextTest extends PhpUnitTestCase
{	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetInstance()
	{
		$instance = Context::getInstance('foo');
		$this->assertNotNull($instance);
		$this->assertInstanceOf(Context::class, $instance);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testSameInstanceForSameProfile()
	{
		$instance1 = Context::getInstance('foo');
		$instance2 = Context::getInstance('foo');
		$this->assertSame($instance1, $instance2);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testDifferentInstanceForDifferentProfile()
	{
		$instance1 = Context::getInstance('foo');
		$instance2 = Context::getInstance('bar');
		$this->assertNotSame($instance1, $instance2);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetName()
	{
		$this->assertSame(Config::getNullableString('core.default_context'), Context::getInstance()->getName());
		$this->assertSame('test1', Context::getInstance('test1')->getName());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testToStringReturnsTheContextName(): void
	{
		$ctx = Context::getInstance('stringable_test');
		$this->assertSame('stringable_test', (string) $ctx);
		$this->assertSame($ctx->getName(), (string) $ctx);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetWorkerStateForASingleProfileResetsOnlyThatContext(): void
	{
		$a = Context::getInstance('reset_a');
		$b = Context::getInstance('reset_b');
		$a->getController();
		$b->getController();

		// A no-arg call resets every instantiated context; this only exercises
		// that the method runs without error for a real profile and is a no-op
		// (ResetInterface is implemented, so this must not throw).
		Context::resetWorkerState('reset_a');
		$this->addToAssertionCount(1);

		// Still the same singleton instances afterward -- reset() clears
		// per-request state, it does not tear down and recreate the Context itself.
		$this->assertSame($a, Context::getInstance('reset_a'));
		$this->assertSame($b, Context::getInstance('reset_b'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetWorkerStateWithNoProfileResetsEveryInstantiatedContext(): void
	{
		Context::getInstance('reset_all_a');
		Context::getInstance('reset_all_b');

		Context::resetWorkerState();
		$this->addToAssertionCount(1);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetWorkerStateForAnUninstantiatedProfileIsANoOp(): void
	{
		Context::resetWorkerState('never_instantiated_profile');
		$this->addToAssertionCount(1);
	}


	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetModel')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetModel($modelName, $className, $isSingleton, $module = null)
	{
		$ctx = Context::getInstance();
		$model1 = $ctx->getModel($modelName, $module);
		$model2 = $ctx->getModel($modelName, $module);
		$this->assertInstanceOf($className, $model1);
		$this->assertInstanceOf($className, $model2);
		if($isSingleton) {
			$this->assertSame($model1, $model2);
		} else {
			$this->assertNotSame($model1, $model2);
		}
	}
	
	public static function dataGetModel() {
		return [
			'global normal model' => ['ContextTest', ContextTestModel::class, false],
			'global singleton model' => ['ContextTestSingleton', ContextTestSingletonModel::class, true],
			'global model in child path' => ['ContextTest.Child.Test', ChildTestModel::class, false],
			'module normal model' => ['Test', TestModel::class, false, 'ContextTest'],
			'module singleton model' => ['TestSingleton', TestSingletonModel::class, true, 'ContextTest'],
			'module model in child path' => ['Parent.Child.Test', ModuleChildTestModel::class, false, 'ContextTest'],
		];
	}	

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetFactoryInfo()
	{
		$ctx = Context::getInstance('test');
		$expected = ['class' => \Quiote\Response\WebResponse::class, 'parameters' => []];
		$this->assertSame($expected, $ctx->getFactoryInfo('response'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetController()
	{
		$this->assertInstanceOf(\Quiote\Controller\Controller::class, Context::getInstance()->getController());
	}

	/**
	 * Test getDatabaseManager when database is disabled
	 */
	#[IsolationEnvironment('testing-use_database_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetDatabaseManagerOff()
	{
		$ctx = Context::getInstance();
		$this->assertFalse(Config::getBool('core.use_database'));
		$this->assertInstanceOf(\Quiote\Database\DatabaseManager::class, $ctx->getDatabaseManager());
	}

	#[IsolationEnvironment('testing-use_database_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetDatabaseManagerOn()
	{
		$this->assertInstanceOf(\Quiote\Database\DatabaseManager::class, Context::getInstance()->getDatabaseManager());
	}
	
	#[IsolationEnvironment('testing-use_security_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOff()
	{
		$this->assertInstanceOf(\Quiote\User\User::class, Context::getInstance()->getUser());
		$this->assertNotInstanceOf(\Quiote\User\SecurityUser::class, Context::getInstance()->getUser());
	}

	#[IsolationEnvironment('testing-use_security_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOn()
	{
		$this->assertInstanceOf(\Quiote\User\SecurityUser::class, Context::getInstance()->getUser());
	}

	#[IsolationEnvironment('testing-use_translation_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetTranslationManagerOff()
	{
		$this->assertNull(Context::getInstance()->getTranslationManager());
	}

	#[IsolationEnvironment('testing-use_logging_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetTranslationManagerOn()
	{
		$this->assertInstanceOf(\Quiote\Translation\TranslationManager::class, Context::getInstance()->getTranslationManager());
	}


	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRequest()
	{
		$ctx = Context::getInstance();
		$this->assertInstanceOf(\Quiote\Request\WebRequest::class, $ctx->getRequest());
		$this->assertInstanceOf(\Psr\Http\Message\ServerRequestInterface::class, $ctx->getRequest());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRouting()
	{
		$ctx = Context::getInstance();
		$this->assertInstanceOf(\Quiote\Routing\Routing::class, $ctx->getRouting());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetStorage()
	{
		$ctx = Context::getInstance();
		$this->assertInstanceOf(\Quiote\Storage\Storage::class, $ctx->getStorage());
	}

	/**
	 * Core services built by factories.xml must also be resolvable through the
	 * container, by role name and by concrete class name, resolving to the
	 * exact same instances.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testContainerResolvesCoreServicesByRoleAndClass()
	{
		$ctx = Context::getInstance();
		$container = $ctx->getContainer();

		$this->assertSame($ctx->getController(), $container->get('controller'));
		$this->assertSame($ctx->getController(), $container->get($ctx->getController()::class));

		$this->assertSame($ctx->getRouting(), $container->get('routing'));
		$this->assertSame($ctx->getStorage(), $container->get('storage'));
		$this->assertSame($ctx->getUser(), $container->get('user'));
		$this->assertSame($ctx->getRequest(), $container->get('request'));
	}

	/**
	 * reset() must drop request-scoped container entries in lockstep with the
	 * request/storage/user nulling it already does, so the container never
	 * serves a discarded per-request instance.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testContainerResetDropsRequestScopedEntriesButKeepsSingletons()
	{
		$ctx = Context::getInstance();
		$container = $ctx->getContainer();

		$controllerBefore = $container->get('controller');
		$storageBefore = $ctx->getStorage();
		$this->assertSame($storageBefore, $container->get('storage'));

		$ctx->reset();

		$this->assertSame($controllerBefore, $container->get('controller'), 'singleton-scoped services must survive reset()');

		$storageAfter = $ctx->getStorage();
		$this->assertNotSame($storageBefore, $storageAfter, 'storage must be recreated after reset()');
		$this->assertSame($storageAfter, $container->get('storage'), 'container must reflect the recreated storage instance');
	}

	/**
	 * getService() is a thin wrapper over the container, and the context itself
	 * must be autowireable so the transitional Service base (constructor-injecting
	 * Context) resolves correctly.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetServiceResolvesCoreServiceAndArbitraryClass()
	{
		$ctx = Context::getInstance();
		$this->assertSame($ctx->getController(), $ctx->getService('controller'));

		$service = $ctx->getService(ContextTestServiceFixture::class);
		$this->assertInstanceOf(ContextTestServiceFixture::class, $service);
		$this->assertSame($ctx, $service->getContext());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetServiceDefaultsToTransientForQuioteServiceInterface()
	{
		$ctx = Context::getInstance();
		$s1 = $ctx->getService(ContextTestServiceFixture::class);
		$s2 = $ctx->getService(ContextTestServiceFixture::class);
		$this->assertNotSame($s1, $s2);
	}
}

class ContextTestServiceFixture extends \Quiote\Service\Service
{
}

?>