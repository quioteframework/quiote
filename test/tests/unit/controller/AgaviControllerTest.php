<?php

use Agavi\Config\AgaviConfig;
use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Controller\AgaviController;
use Agavi\Exception\AgaviException;
use Agavi\Testing\Attributes\AgaviIsolationEnvironment;
use Agavi\AgaviContext;

class TestController extends AgaviController
{
	public function redirect($to): never
	{
		throw new AgaviException('N/A');
	}
}

#[AgaviIsolationEnvironment('testing')]
// Temporarily disabled: #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class AgaviControllerTest extends AgaviPhpUnitTestCase
{
	protected $_controller = null;
	protected $_context = null;
	#[\Override]
    public function setUp(): void
	{
		// ReInitialize the Context between tests to start fresh
		$this->_context = AgaviContext::getInstance();
		$this->_controller = $this->_context->getController();
		$this->_controller->initialize($this->_context, []);
	}

	public function testNewController()
	{
		$controller = $this->_controller;
		$this->assertInstanceOf(\Agavi\Controller\AgaviController::class, $controller);

		$context = $controller->getContext();
		$this->assertInstanceOf(\Agavi\AgaviContext::class, $context);

		$ctx1 = $controller->getContext();
		$ctx2 = AgaviContext::getInstance();
		$this->assertSame($ctx1, $ctx2);
	}

	public function testActionImplementsCorrectInterface()
	{
		// Test that created actions implement AgaviAction interface
		$controller = $this->_controller;

		$action = $controller->createActionInstance('ControllerTests', 'ControllerTest');
		$this->assertInstanceOf(\Agavi\Action\AgaviAction::class, $action);

		// Test that the action class exists and is loadable
		$this->assertTrue(class_exists(\Sandbox\Modules\ControllerTests\Actions\ControllerTestAction::class));

		// Test reflection to ensure it's properly structured
		$reflection = new \ReflectionClass($action);
		$this->assertTrue($reflection->hasMethod('execute'));
	}

	public function testGetActionFromModule()
	{
		// TODO: check all other existing naming schemes for actions

		$action = $this->_controller->createActionInstance('ControllerTests', 'ControllerTest');
		$this->assertInstanceOf(\Sandbox\Modules\ControllerTests\Actions\ControllerTestAction::class, $action);
		$this->assertInstanceOf(\Agavi\Action\AgaviAction::class, $action);

	}

	public function testGetInvalidActionFromModule() {
		$this->expectException(\Agavi\Exception\AgaviClassNotFoundException::class);
		$this->_controller->createActionInstance('ControllerTests', 'NonExistant');
	}

	public function testGetContext()
	{
		$this->assertSame(AgaviContext::getInstance(), AgaviContext::getInstance()->getController()->getContext());
	}

	public function testCreateViewInstance()
	{
		$controller = $this->_controller;
		$this->assertInstanceOf(
			\Sandbox\Modules\ControllerTests\Views\ControllerTestSuccessView::class,
			$controller->createViewInstance('ControllerTests', 'ControllerTestSuccess')
		);
		$this->assertInstanceOf(
			\Sandbox\Modules\ControllerTests\Views\ControllerTestErrorView::class,
			$controller->createViewInstance('ControllerTests', 'ControllerTestError')
		);
	}

	public function testModelImplementsCorrectInterface()
	{
		// Test that models can be loaded and implement AgaviModel interface
		$context = $this->_context;

		$model = $context->getModel('ControllerTest', 'ControllerTests');
		$this->assertInstanceOf(\Agavi\Model\AgaviModel::class, $model);

		// Test that the model class exists and is loadable
		$this->assertTrue(class_exists(\Sandbox\Modules\ControllerTests\Models\ControllerTestModel::class));

		// Test reflection to ensure it's properly structured
		$reflection = new \ReflectionClass($model);
		$this->assertTrue($reflection->isSubclassOf(\Agavi\Model\AgaviModel::class));
	}

	public function testModelExists()
	{
		$controller = $this->_controller;
		$this->assertTrue($controller->modelExists('ControllerTests', 'ControllerTest'));
		$this->assertFalse($controller->modelExists('Test', 'Bunk'));
		$this->assertFalse($controller->modelExists('Bunk', 'Bunk'));
	}

	public function testViewImplementsCorrectInterface()
	{
		// Test that created views implement AgaviView interface
		$controller = $this->_controller;

		$view = $controller->createViewInstance('ControllerTests', 'ControllerTestSuccess');
		$this->assertInstanceOf(\Agavi\View\AgaviView::class, $view);

		// Test that the view class exists and is loadable
		$this->assertTrue(class_exists(\Sandbox\Modules\ControllerTests\Views\ControllerTestSuccessView::class));

		// Test reflection to ensure it's properly structured
		$reflection = new \ReflectionClass($view);
		$this->assertTrue($reflection->hasMethod('execute'));

		// Test error view as well
		$errorView = $controller->createViewInstance('ControllerTests', 'ControllerTestError');
		$this->assertInstanceOf(\Agavi\View\AgaviView::class, $errorView);
		$this->assertTrue(class_exists(\Sandbox\Modules\ControllerTests\Views\ControllerTestErrorView::class));
	}

	public function testViewExists()
	{
		$controller = $this->_controller;
		$this->assertTrue($controller->viewExists('ControllerTests', 'ControllerTestSuccess'));
		$this->assertFalse($controller->viewExists('Test', 'Bunk'));
		$this->assertFalse($controller->viewExists('Bunk', 'Bunk'));
	}



	public function testGetOutputTypeInfo()
	{
		$controller = $this->_controller;

		$info_ex = [
			'http_headers' => [
				'Content-Type' => 'text/html; charset=UTF-8',
			],
		];

		$info = $controller->getOutputType();
		$this->assertSame($info_ex, $info->getParameters());

		$info_ex = [
		];
		$info = $controller->getOutputType('controllerTest');
		$this->assertSame($info_ex, $info->getParameters());

		try {
			$controller->getOutputType('nonexistant');
			$this->fail('Expected AgaviException not thrown!');
		} catch(AgaviException) {
		}
	}


/* 
	// TODO: moved to AgaviResponse
	public function testsetContentType()
	{
		$controller = AgaviContext::getInstance('test')->getController();
		$ctype = $controller->getContentType();
		$controller->setContentType('image/jpeg');
		$this->assertEquals($controller->getContentType(), 'image/jpeg');
		$controller->setContentType($ctype);
	}

	public function testclearHTTPHeaders()
	{
		$controller = AgaviContext::getInstance('test')->getController();
		$controller->clearHTTPHeaders();
		$this->assertEquals($controller->getHTTPHeaders(), array());
	}

	public function testgetHTTPHeader()
	{
		$controller = AgaviContext::getInstance('test')->getController();
		$this->assertEquals($controller->getHTTPHeader('unset'), null);
	}

	public function testhasHTTPHeader()
	{
		$controller = AgaviContext::getInstance('test')->getController();
		$controller->clearHTTPHeaders();
		$controller->setHTTPHeader('testme', 'whatever');
		$this->assertTrue($controller->hasHTTPHeader('testme'));
		$this->assertFalse($controller->hasHTTPHeader('iamnotset'));
	}

	public function testsetHTTPHeader()
	{
		$controller = AgaviContext::getInstance('test')->getController();
		$controller->setHTTPHeader('sometest', 'fubar');
		$this->assertEquals($controller->getHTTPHeader('sometest'), array('fubar'));
		$controller->setHTTPHeader('sometest', 'foo');
		$this->assertEquals($controller->getHTTPHeader('sometest'), array('foo'));
		$controller->setHTTPHeader('sometest', 'bar', false);
		$this->assertEquals($controller->getHTTPHeader('sometest'), array('foo', 'bar'));
		$controller->setHTTPHeader('multiple', array('first', 'second'));
		$this->assertEquals($controller->getHTTPHeader('multiple'), array('first', 'second'));
	}

	public function testgetHTTPStatusCode()
	{
		$controller = AgaviContext::getInstance('test')->getController();
		$this->assertEquals($controller->getHTTPStatusCode(), null);
	}

	public function testsetHTTPStatusCode()
	{
		$controller = AgaviContext::getInstance('test')->getController();
		$controller->setHTTPStatusCode('404');
		$this->assertEquals($controller->getHTTPStatusCode(), '404');
		$controller->setHTTPStatusCode(403);
		$this->assertEquals($controller->getHTTPStatusCode(), '403');
		$controller->setHTTPStatusCode('123');
		$this->assertEquals($controller->getHTTPStatusCode(), '403');
		$controller->setHTTPStatusCode(123);
		$this->assertEquals($controller->getHTTPStatusCode(), '403');
	}

	// TODO: moved to routing
	function testgenURL()
	{
		$routing = AgaviContext::getInstance('test')->getRouting();
		$this->assertEquals($controller->genURL('index.php', array('foo' =>'bar')), 'index.php?foo=bar');
		$this->assertEquals($controller->genURL(null, array('foo' =>'bar')), $_SERVER['SCRIPT_NAME'] . '?foo=bar');
		$this->assertEquals($controller->genURL(array('foo' =>'bar'), 'index.php'), 'index.php?foo=bar');
		$this->assertEquals($controller->genURL(array('foo' =>'bar')), $_SERVER['SCRIPT_NAME'] . '?foo=bar');
	}
*/
}

?>