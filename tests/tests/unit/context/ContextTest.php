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
	public function testGetInstance(): void
	{
		$instance = Context::getInstance('foo');
		$this->assertInstanceOf(Context::class, $instance);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testSameInstanceForSameProfile(): void
	{
		$instance1 = Context::getInstance('foo');
		$instance2 = Context::getInstance('foo');
		$this->assertSame($instance1, $instance2);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testDifferentInstanceForDifferentProfile(): void
	{
		$instance1 = Context::getInstance('foo');
		$instance2 = Context::getInstance('bar');
		$this->assertNotSame($instance1, $instance2);
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetName(): void
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

	// The worker-boundary reset behaviour -- every live context, ordering, guarding -- belongs to
	// ContextRegistry now and is tested in ContextRegistryTest.


	/** @param class-string $className */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataGetModel')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetModel(string $modelName, string $className, bool $isSingleton, ?string $module = null): void
	{
		$ctx = Context::getInstance();
		$model1 = $ctx->getModelLocator()->get($modelName, $module);
		$model2 = $ctx->getModelLocator()->get($modelName, $module);
		$this->assertInstanceOf($className, $model1);
		$this->assertInstanceOf($className, $model2);
		if($isSingleton) {
			$this->assertSame($model1, $model2);
		} else {
			$this->assertNotSame($model1, $model2);
		}
	}
	
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetModelThrowsForAnUnresolvableModelName(): void
	{
		$this->expectException(\Quiote\Exception\QuioteException::class);
		Context::getInstance()->getModelLocator()->get('ThisModelDoesNotExistAnywhere');
	}

	/**
	 * getModel() and the container must reach the same locator, so a model resolved through an
	 * injected ModelLocator shares the singleton instances with one resolved through the context.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testTheContainerResolvesTheSameModelLocatorTheContextUses(): void
	{
		$ctx = Context::getInstance();
		$locator = $ctx->getModelLocator();

		$this->assertSame($locator, $ctx->getModelLocator(), 'the locator is built once per context');
		$this->assertSame($locator, $ctx->getContainer()->get(\Quiote\Model\ModelLocator::class));
		$this->assertSame($locator, $ctx->getContainer()->get('modelLocator'));
		$this->assertSame(
			$ctx->getModelLocator()->get('ContextTestSingleton'),
			$locator->get('ContextTestSingleton'),
			'both paths answer the same singleton instance',
		);
	}

	/**
	 * reset() runs at the worker request boundary, and a singleton model holding request N's
	 * data must not be handed to request N+1.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testResetDropsSingletonModelInstances(): void
	{
		$ctx = Context::getInstance();
		$before = $ctx->getModelLocator()->get('ContextTestSingleton');

		$ctx->reset();

		$this->assertNotSame($before, $ctx->getModelLocator()->get('ContextTestSingleton'));
	}

	/** @return array<string, array{0: string, 1: class-string, 2: bool, 3?: string}> */
	public static function dataGetModel(): array {
		return [
			'global normal model' => ['ContextTest', ContextTestModel::class, false],
			'global singleton model' => ['ContextTestSingleton', ContextTestSingletonModel::class, true],
			'global model in child path' => ['ContextTest.Child.Test', ChildTestModel::class, false],
			'module normal model' => ['Test', TestModel::class, false, 'ContextTest'],
			'module singleton model' => ['TestSingleton', TestSingletonModel::class, true, 'ContextTest'],
			'module model in child path' => ['Parent.Child.Test', ModuleChildTestModel::class, false, 'ContextTest'],
		];
	}	

	/**
	 * The `response` slot is declared by the factories configuration and resolved from the container,
	 * transiently: an on-demand slot means a fresh instance per request for one, which is what
	 * Context::createInstanceFor() used to promise.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAnOnDemandSlotResolvesFreshFromTheContainer(): void
	{
		$ctx = Context::getInstance('test');

		$first = $ctx->getContainer()->get(\Quiote\Response\WebResponse::class);
		$second = $ctx->getContainer()->get(\Quiote\Response\WebResponse::class);

		$this->assertInstanceOf(\Quiote\Response\WebResponse::class, $first);
		$this->assertNotSame($first, $second, 'a slot is transient: one instance per ask');
		// Reachable by role name as well, which is how the framework's own code asks for it.
		$this->assertInstanceOf(\Quiote\Response\WebResponse::class, $ctx->getContainer()->get('response'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetController(): void
	{
		$this->assertInstanceOf(\Quiote\Controller\Controller::class, Context::getInstance()->getController());
	}

	/**
	 * Test getDatabaseManager when database is disabled
	 */
	#[IsolationEnvironment('testing-use_database_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetDatabaseManagerOff(): void
	{
		$ctx = Context::getInstance();
		$this->assertFalse(Config::getBool('core.use_database'));
		$this->assertInstanceOf(\Quiote\Database\DatabaseManager::class, $ctx->getDatabaseManager());
	}

	#[IsolationEnvironment('testing-use_database_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetDatabaseManagerOn(): void
	{
		$this->assertInstanceOf(\Quiote\Database\DatabaseManager::class, Context::getInstance()->getDatabaseManager());
	}
	
	#[IsolationEnvironment('testing-use_security_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOff(): void
	{
		$this->assertInstanceOf(\Quiote\User\User::class, Context::getInstance()->getUser());
		$this->assertNotInstanceOf(\Quiote\User\SecurityUser::class, Context::getInstance()->getUser());
	}

	#[IsolationEnvironment('testing-use_security_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOn(): void
	{
		$this->assertInstanceOf(\Quiote\User\SecurityUser::class, Context::getInstance()->getUser());
	}

	#[IsolationEnvironment('testing-use_translation_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetTranslationManagerOff(): void
	{
		$this->assertNull(Context::getInstance()->getTranslationManager());
	}

	#[IsolationEnvironment('testing-use_logging_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetTranslationManagerOn(): void
	{
		$this->assertInstanceOf(\Quiote\Translation\TranslationManager::class, Context::getInstance()->getTranslationManager());
	}


	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRequest(): void
	{
		$ctx = Context::getInstance();
		$this->assertInstanceOf(\Quiote\Request\WebRequest::class, $ctx->getRequest());
		$this->assertInstanceOf(\Psr\Http\Message\ServerRequestInterface::class, $ctx->getRequest());
	}

	/**
	 * The request, routing and user are nulled at the worker request boundary and rebuilt
	 * from captured factory metadata on next access. The rebuilt instance must be a
	 * different object, and the container must serve it rather than the discarded one.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testRequestIsRebuiltAfterResetAndReRegisteredInTheContainer(): void
	{
		$ctx = Context::getInstance('rebuild_request_test');
		$first = $ctx->getRequest();
		$this->assertSame($first, $ctx->getContainer()->get('request'));

		$ctx->reset();

		$second = $ctx->getRequest();
		$this->assertNotSame($first, $second);
		$this->assertInstanceOf(\Quiote\Request\WebRequest::class, $second);
		$this->assertSame($second, $ctx->getContainer()->get('request'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testUserIsRebuiltAfterResetAndReRegisteredInTheContainer(): void
	{
		$ctx = Context::getInstance('rebuild_user_test');
		$first = $ctx->getUser();
		$this->assertSame($first, $ctx->getContainer()->get('user'));

		$ctx->reset();

		$second = $ctx->getUser();
		$this->assertNotSame($first, $second);
		$this->assertSame($second, $ctx->getContainer()->get('user'));
	}

	/**
	 * Routing is rebuilt without the initialize()/startup() pair, so a repeat access must
	 * still hand back the same instance rather than constructing one per call.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testRoutingIsMemoizedAcrossCalls(): void
	{
		$ctx = Context::getInstance('rebuild_routing_test');

		$this->assertSame($ctx->getRouting(), $ctx->getRouting());
	}

	/**
	 * Without a factory declaration there is nothing to rebuild from, and the failure has to name
	 * the component rather than surfacing as a null dereference later.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testRebuildWithoutAFactoryDeclarationThrowsNamingTheComponent(): void
	{
		$ctx = Context::getInstance('rebuild_missing_info_test');
		$ctx->getRequest();

		$reflection = new \ReflectionObject($ctx);
		$reflection->getProperty('request')->setValue($ctx, null);
		// Drop the declarations the rebuild reads from, which is what a context that never
		// completed initialize() looks like.
		$reflection->getProperty('factoryDefinitions')->setValue($ctx, null);

		$this->expectException(\Quiote\Exception\QuioteException::class);
		$this->expectExceptionMessage('Request object is null and no factory declaration is available');

		$ctx->getRequest();
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetAssetRegistryReturnsSameInstanceUntilReset(): void
	{
		$ctx = Context::getInstance('asset_registry_test');
		$registry1 = $ctx->getContainer()->get(\Quiote\Asset\AssetRegistry::class);
		$this->assertInstanceOf(\Quiote\Asset\AssetRegistry::class, $registry1);
		$registry2 = $ctx->getContainer()->get(\Quiote\Asset\AssetRegistry::class);
		$this->assertSame($registry1, $registry2, 'Lazily created AssetRegistry must be a per-Context singleton within a request');

		$registry1->addCss('css/one.css');
		$ctx->reset();

		$registry3 = $ctx->getContainer()->get(\Quiote\Asset\AssetRegistry::class);
		$this->assertNotSame($registry1, $registry3, 'reset() must rebuild the registry so assets never leak between requests in worker mode');
		$this->assertSame([], $registry3->css(), 'A freshly rebuilt registry must start empty');
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRouting(): void
	{
		$ctx = Context::getInstance();
		$this->assertInstanceOf(\Quiote\Routing\Routing::class, $ctx->getRouting());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetSessionBag(): void
	{
		$ctx = Context::getInstance();
		$this->assertInstanceOf(\Quiote\Session\SessionBagInterface::class, $ctx->getContainer()->get(\Quiote\Session\SessionBagInterface::class));
	}

	/**
	 * Core services built by factories.xml must also be resolvable through the
	 * container, by role name and by concrete class name, resolving to the
	 * exact same instances.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testContainerResolvesCoreServicesByRoleAndClass(): void
	{
		$ctx = Context::getInstance();
		$container = $ctx->getContainer();

		$this->assertSame($ctx->getController(), $container->get('controller'));
		$this->assertSame($ctx->getController(), $container->get($ctx->getController()::class));

		$this->assertSame($ctx->getRouting(), $container->get('routing'));
		$this->assertSame($ctx->getContainer()->get(\Quiote\Session\SessionBagInterface::class), $container->get('sessionBag'));
		$this->assertSame($ctx->getUser(), $container->get('user'));
		$this->assertSame($ctx->getRequest(), $container->get('request'));
	}

	/**
	 * reset() must drop request-scoped container entries in lockstep with the
	 * request/session/user nulling it already does, so the container never
	 * serves a discarded per-request instance.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testContainerResetDropsRequestScopedEntriesButKeepsSingletons(): void
	{
		$ctx = Context::getInstance();
		$container = $ctx->getContainer();

		$controllerBefore = $container->get('controller');
		$bagBefore = $ctx->getContainer()->get(\Quiote\Session\SessionBagInterface::class);
		$this->assertSame($bagBefore, $container->get('sessionBag'));

		$ctx->reset();

		$this->assertSame($controllerBefore, $container->get('controller'), 'singleton-scoped services must survive reset()');

		$bagAfter = $ctx->getContainer()->get(\Quiote\Session\SessionBagInterface::class);
		$this->assertNotSame($bagBefore, $bagAfter, 'the session bag must not survive reset()');
		$this->assertSame($bagAfter, $container->get('sessionBag'), 'container must reflect the new bag');
	}

	/**
	 * getService() is a thin wrapper over the container, and the context itself
	 * must be autowireable so the transitional Service base (constructor-injecting
	 * Context) resolves correctly.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetServiceResolvesCoreServiceAndArbitraryClass(): void
	{
		$ctx = Context::getInstance();
		$this->assertSame($ctx->getController(), $ctx->getContainer()->get('controller'));

		$service = $ctx->getContainer()->get(ContextTestServiceFixture::class);
		$this->assertInstanceOf(ContextTestServiceFixture::class, $service);
		$this->assertSame($ctx, $service->getContext());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetServiceDefaultsToTransientForQuioteServiceInterface(): void
	{
		$ctx = Context::getInstance();
		$s1 = $ctx->getContainer()->get(ContextTestServiceFixture::class);
		$s2 = $ctx->getContainer()->get(ContextTestServiceFixture::class);
		$this->assertNotSame($s1, $s2);
	}
}

class ContextTestServiceFixture extends \Quiote\Service\Service
{
}

?>