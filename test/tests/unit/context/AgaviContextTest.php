<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Testing\Attributes\AgaviIsolationEnvironment;
use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Sandbox\Models\ContextTest\Child\TestModel as ChildTestModel;
use Sandbox\Models\ContextTestModel;
use Sandbox\Models\ContextTestSingletonModel;
use Sandbox\Modules\ContextTest\Models\TestModel;
use Sandbox\Modules\ContextTest\Models\TestSingletonModel;
use Sandbox\Modules\ContextTest\Models\Parent\Child\TestModel as ModuleChildTestModel;

#[AgaviIsolationEnvironment('testing')]
class AgaviContextTest extends AgaviPhpUnitTestCase
{	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetInstance()
	{
		$instance = AgaviContext::getInstance('foo');
		$this->assertNotNull($instance);
		$this->assertInstanceOf(AgaviContext::class, $instance);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testSameInstanceForSameProfile()
	{
		$instance1 = AgaviContext::getInstance('foo');
		$instance2 = AgaviContext::getInstance('foo');
		$this->assertSame($instance1, $instance2);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testDifferentInstanceForDifferentProfile()
	{
		$instance1 = AgaviContext::getInstance('foo');
		$instance2 = AgaviContext::getInstance('bar');
		$this->assertNotSame($instance1, $instance2);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetName()
	{
		$this->assertSame(AgaviConfig::get('core.default_context'), AgaviContext::getInstance()->getName());
		$this->assertSame('test1', AgaviContext::getInstance('test1')->getName());
	}


	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetModel')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetModel($modelName, $className, $isSingleton, $module = null)
	{
		$ctx = AgaviContext::getInstance();
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
		$ctx = AgaviContext::getInstance('test');
		$expected = ['class' => \Agavi\Response\AgaviWebResponse::class, 'parameters' => []];
		$this->assertSame($expected, $ctx->getFactoryInfo('response'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetController()
	{
		$this->assertInstanceOf(\Agavi\Controller\AgaviController::class, AgaviContext::getInstance()->getController());
	}

	/**
	 * Test getDatabaseManager when database is disabled
	 */
	#[AgaviIsolationEnvironment('testing-use_database_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetDatabaseManagerOff()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertFalse(AgaviConfig::get('core.use_database'));
		$this->assertInstanceOf(\Agavi\Database\AgaviDatabaseManager::class, $ctx->getDatabaseManager());
	}

	#[AgaviIsolationEnvironment('testing-use_database_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetDatabaseManagerOn()
	{
		$this->assertInstanceOf(\Agavi\Database\AgaviDatabaseManager::class, AgaviContext::getInstance()->getDatabaseManager());
	}
	
	#[AgaviIsolationEnvironment('testing-use_security_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOff()
	{
		$this->assertInstanceOf(\Agavi\User\AgaviUser::class, AgaviContext::getInstance()->getUser());
		$this->assertNotInstanceOf(\Agavi\User\AgaviSecurityUser::class, AgaviContext::getInstance()->getUser());
	}

	#[AgaviIsolationEnvironment('testing-use_security_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOn()
	{
		$this->assertInstanceOf(\Agavi\User\AgaviSecurityUser::class, AgaviContext::getInstance()->getUser());
	}

	#[AgaviIsolationEnvironment('testing-use_translation_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetTranslationManagerOff()
	{
		$this->assertNull(AgaviContext::getInstance()->getTranslationManager());
	}

	#[AgaviIsolationEnvironment('testing-use_logging_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetTranslationManagerOn()
	{
		$this->assertInstanceOf(\Agavi\Translation\AgaviTranslationManager::class, AgaviContext::getInstance()->getTranslationManager());
	}


	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRequest()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertInstanceOf(\Agavi\Request\AgaviWebRequest::class, $ctx->getRequest());
		$this->assertInstanceOf(\Psr\Http\Message\ServerRequestInterface::class, $ctx->getRequest());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRouting()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertInstanceOf(\Agavi\Routing\AgaviRouting::class, $ctx->getRouting());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetStorage()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertInstanceOf(\Agavi\Storage\AgaviStorage::class, $ctx->getStorage());
	}

	/**
	 * DI migration Phase 1 (docs/DI_MIGRATION_PLAN.md): core services built by
	 * factories.xml must also be resolvable through the container, by role name
	 * and by concrete class name, resolving to the exact same instances.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testContainerResolvesCoreServicesByRoleAndClass()
	{
		$ctx = AgaviContext::getInstance();
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
		$ctx = AgaviContext::getInstance();
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
	 * DI migration Phase 3 (docs/DI_MIGRATION_PLAN.md): getService() is a thin wrapper
	 * over the container, and the context itself must be autowireable so the transitional
	 * AgaviService base (constructor-injecting AgaviContext) resolves correctly.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetServiceResolvesCoreServiceAndArbitraryClass()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertSame($ctx->getController(), $ctx->getService('controller'));

		$service = $ctx->getService(AgaviContextTestServiceFixture::class);
		$this->assertInstanceOf(AgaviContextTestServiceFixture::class, $service);
		$this->assertSame($ctx, $service->getContext());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetServiceDefaultsToTransientForAgaviServiceInterface()
	{
		$ctx = AgaviContext::getInstance();
		$s1 = $ctx->getService(AgaviContextTestServiceFixture::class);
		$s2 = $ctx->getService(AgaviContextTestServiceFixture::class);
		$this->assertNotSame($s1, $s2);
	}
}

class AgaviContextTestServiceFixture extends \Agavi\Service\AgaviService
{
}

?>