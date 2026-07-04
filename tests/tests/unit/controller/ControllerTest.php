<?php

use Quiote\Config\Config;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Controller\Controller;
use Quiote\Exception\QuioteException;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Context;

class TestController extends Controller
{
	public function redirect($to): never
	{
		throw new Exception('N/A');
	}
}

#[IsolationEnvironment('testing')]
// Temporarily disabled: #[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ControllerTest extends PhpUnitTestCase
{
	protected $_controller = null;
	protected $_context = null;
	#[\Override]
    public function setUp(): void
	{
		// ReInitialize the Context between tests to start fresh
		$this->_context = Context::getInstance();
		$this->_controller = $this->_context->getController();
		$this->_controller->initialize($this->_context, []);
	}

	public function testNewController()
	{
		$controller = $this->_controller;
		$this->assertInstanceOf(\Quiote\Controller\Controller::class, $controller);

		$context = $controller->getContext();
		$this->assertInstanceOf(\Quiote\Context::class, $context);

		$ctx1 = $controller->getContext();
		$ctx2 = Context::getInstance();
		$this->assertSame($ctx1, $ctx2);
	}

	public function testActionImplementsCorrectInterface()
	{
		// Test that created actions implement Action interface
		$controller = $this->_controller;

		$action = $controller->createActionInstance('ControllerTests', 'ControllerTest');
		$this->assertInstanceOf(\Quiote\Action\Action::class, $action);

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
		$this->assertInstanceOf(\Quiote\Action\Action::class, $action);

	}

	public function testGetInvalidActionFromModule() {
		$this->expectException(\Quiote\Exception\ClassNotFoundException::class);
		$this->_controller->createActionInstance('ControllerTests', 'NonExistant');
	}

	public function testGetContext()
	{
		$this->assertSame(Context::getInstance(), Context::getInstance()->getController()->getContext());
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
		// Test that models can be loaded and implement Model interface
		$context = $this->_context;

		$model = $context->getModel('ControllerTest', 'ControllerTests');
		$this->assertInstanceOf(\Quiote\Model\Model::class, $model);

		// Test that the model class exists and is loadable
		$this->assertTrue(class_exists(\Sandbox\Modules\ControllerTests\Models\ControllerTestModel::class));

		// Test reflection to ensure it's properly structured
		$reflection = new \ReflectionClass($model);
		$this->assertTrue($reflection->isSubclassOf(\Quiote\Model\Model::class));
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
		// Test that created views implement View interface
		$controller = $this->_controller;

		$view = $controller->createViewInstance('ControllerTests', 'ControllerTestSuccess');
		$this->assertInstanceOf(\Quiote\View\View::class, $view);

		// Test that the view class exists and is loadable
		$this->assertTrue(class_exists(\Sandbox\Modules\ControllerTests\Views\ControllerTestSuccessView::class));

		// Test reflection to ensure it's properly structured
		$reflection = new \ReflectionClass($view);
		$this->assertTrue($reflection->hasMethod('execute'));

		// Test error view as well
		$errorView = $controller->createViewInstance('ControllerTests', 'ControllerTestError');
		$this->assertInstanceOf(\Quiote\View\View::class, $errorView);
		$this->assertTrue(class_exists(\Sandbox\Modules\ControllerTests\Views\ControllerTestErrorView::class));
	}

	public function testViewExists()
	{
		$controller = $this->_controller;
		$this->assertTrue($controller->viewExists('ControllerTests', 'ControllerTestSuccess'));
		$this->assertFalse($controller->viewExists('Test', 'Bunk'));
		$this->assertFalse($controller->viewExists('Bunk', 'Bunk'));
	}

	/**
	 * createActionInstance() routes through Container::make(), so an action's
	 * constructor-typed dependency must be autowired, and every call must
	 * build a fresh instance (never cached like get()).
	 */
	public function testCreateActionInstanceAutowiresConstructorDependency()
	{
		$controller = $this->_controller;

		$action1 = $controller->createActionInstance('ControllerTests', 'ControllerTestDi');
		$this->assertInstanceOf(\Sandbox\Modules\ControllerTests\Actions\ControllerTestDiAction::class, $action1);
		$this->assertInstanceOf(\Sandbox\Services\ControllerTestDiService::class, $action1->service);

		$action2 = $controller->createActionInstance('ControllerTests', 'ControllerTestDi');
		$this->assertNotSame($action1, $action2, 'each dispatch must get its own action instance');
		$this->assertNotSame($action1->service, $action2->service, 'ControllerTestDiService implements ServiceInterface, so it defaults to transient scope');
	}

	/**
	 * Same as above for createViewInstance() — the second choke point that
	 * routes through the container.
	 */
	public function testCreateViewInstanceAutowiresConstructorDependency()
	{
		$controller = $this->_controller;

		$view1 = $controller->createViewInstance('ControllerTests', 'ControllerTestDiSuccess');
		$this->assertInstanceOf(\Sandbox\Modules\ControllerTests\Views\ControllerTestDiSuccessView::class, $view1);
		$this->assertInstanceOf(\Sandbox\Services\ControllerTestDiService::class, $view1->service);

		$view2 = $controller->createViewInstance('ControllerTests', 'ControllerTestDiSuccess');
		$this->assertNotSame($view1, $view2, 'each dispatch must get its own view instance');
	}

	/**
	 * Actions/views with no constructor are unaffected by the Container::make() switch —
	 * they still hit the plain `new $class()` branch and behave identically to before.
	 */
	public function testCreateActionInstanceStillWorksForActionsWithNoConstructor()
	{
		$action1 = $this->_controller->createActionInstance('ControllerTests', 'ControllerTest');
		$action2 = $this->_controller->createActionInstance('ControllerTests', 'ControllerTest');
		$this->assertInstanceOf(\Sandbox\Modules\ControllerTests\Actions\ControllerTestAction::class, $action1);
		$this->assertNotSame($action1, $action2);
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
			$this->fail('Expected Exception not thrown!');
		} catch (\Exception) {
		}
	}


/* 
	// TODO: moved to Response
	public function testsetContentType()
	{
		$controller = Context::getInstance('test')->getController();
		$ctype = $controller->getContentType();
		$controller->setContentType('image/jpeg');
		$this->assertEquals($controller->getContentType(), 'image/jpeg');
		$controller->setContentType($ctype);
	}

	public function testclearHTTPHeaders()
	{
		$controller = Context::getInstance('test')->getController();
		$controller->clearHTTPHeaders();
		$this->assertEquals($controller->getHTTPHeaders(), array());
	}

	public function testgetHTTPHeader()
	{
		$controller = Context::getInstance('test')->getController();
		$this->assertEquals($controller->getHTTPHeader('unset'), null);
	}

	public function testhasHTTPHeader()
	{
		$controller = Context::getInstance('test')->getController();
		$controller->clearHTTPHeaders();
		$controller->setHTTPHeader('testme', 'whatever');
		$this->assertTrue($controller->hasHTTPHeader('testme'));
		$this->assertFalse($controller->hasHTTPHeader('iamnotset'));
	}

	public function testsetHTTPHeader()
	{
		$controller = Context::getInstance('test')->getController();
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
		$controller = Context::getInstance('test')->getController();
		$this->assertEquals($controller->getHTTPStatusCode(), null);
	}

	public function testsetHTTPStatusCode()
	{
		$controller = Context::getInstance('test')->getController();
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
		$routing = Context::getInstance('test')->getRouting();
		$this->assertEquals($controller->genURL('index.php', array('foo' =>'bar')), 'index.php?foo=bar');
		$this->assertEquals($controller->genURL(null, array('foo' =>'bar')), $_SERVER['SCRIPT_NAME'] . '?foo=bar');
		$this->assertEquals($controller->genURL(array('foo' =>'bar'), 'index.php'), 'index.php?foo=bar');
		$this->assertEquals($controller->genURL(array('foo' =>'bar')), $_SERVER['SCRIPT_NAME'] . '?foo=bar');
	}
*/
}

?>