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
		return array(
			'global normal model' => array('ContextTest', ContextTestModel::class, false),
			'global singleton model' => array('ContextTestSingleton', ContextTestSingletonModel::class, true),
			'global model in child path' => array('ContextTest.Child.Test', ChildTestModel::class, false),
			'module normal model' => array('Test', TestModel::class, false, 'ContextTest'),
			'module singleton model' => array('TestSingleton', TestSingletonModel::class, true, 'ContextTest'),
			'module model in child path' => array('Parent.Child.Test', ModuleChildTestModel::class, false, 'ContextTest'),
		);
	}	

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetFactoryInfo()
	{
		$ctx = AgaviContext::getInstance('test');
		$expected = array('class' => 'Agavi\Response\AgaviWebResponse', 'parameters' => array());
		$this->assertSame($expected, $ctx->getFactoryInfo('response'));
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetController()
	{
		$this->assertInstanceOf('Agavi\Controller\AgaviController', AgaviContext::getInstance()->getController());
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
		$this->assertInstanceOf('Agavi\Database\AgaviDatabaseManager', $ctx->getDatabaseManager());
	}

	#[AgaviIsolationEnvironment('testing-use_database_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetDatabaseManagerOn()
	{
		$this->assertInstanceOf('Agavi\Database\AgaviDatabaseManager', AgaviContext::getInstance()->getDatabaseManager());
	}
	
	#[AgaviIsolationEnvironment('testing-use_security_off')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOff()
	{
		$this->assertInstanceOf('Agavi\User\AgaviUser', AgaviContext::getInstance()->getUser());
		$this->assertNotInstanceOf('Agavi\User\AgaviSecurityUser', AgaviContext::getInstance()->getUser());
	}

	#[AgaviIsolationEnvironment('testing-use_security_on')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetUserSecurityOn()
	{
		$this->assertInstanceOf('Agavi\User\AgaviSecurityUser', AgaviContext::getInstance()->getUser());
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
		$this->assertInstanceOf('Agavi\Translation\AgaviTranslationManager', AgaviContext::getInstance()->getTranslationManager());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetLoggerManager()
	{
		$this->assertInstanceOf('Agavi\Logging\AgaviLoggerManager', AgaviContext::getInstance()->getLoggerManager());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRequest()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertInstanceOf('Agavi\Request\AgaviWebRequest', $ctx->getRequest());
		$this->assertInstanceOf(\Psr\Http\Message\ServerRequestInterface::class, $ctx->getRequest());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetRouting()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertInstanceOf('Agavi\Routing\AgaviRouting', $ctx->getRouting());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testGetStorage()
	{
		$ctx = AgaviContext::getInstance();
		$this->assertInstanceOf('Agavi\Storage\AgaviStorage', $ctx->getStorage());
	}
}

?>