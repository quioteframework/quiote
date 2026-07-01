<?php

use Quiote\Action\Action;
use Quiote\Request\RequestDataHolder;
use Quiote\Request\WebRequest;
use Quiote\Util\ParameterHolder;
use Quiote\Testing\UnitTestCase;

class SampleAction extends Action {
	public function execute(ParameterHolder $parameters)
	{
	}
}

class ActionTest extends UnitTestCase
{
	private $_action = null;

	#[\Override]
    public function setUp(): void
	{
		$this->_action = new SampleAction();
		// Initialize action with lightweight initialization context (descriptor-less)
		$controller = $this->getContext()->getController();
		// Use synthetic descriptor (module/action need not exist for initialization tests)
		$descriptor = new \Quiote\Execution\ActionDescriptor('Foo','Bar','GET','html', false);
		$lw = new \Quiote\Execution\LightweightActionInitContext(
			$this->getContext(),
			$descriptor->module,
			$descriptor->action,
			$descriptor->method,
			$descriptor->outputType,
			new WebRequest(),
			$controller->getGlobalResponse()
		);
		$this->_action->initialize($lw);
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->_action = null;
	}

	public function testgetContext()
	{
		$context = $this->getContext();
		$actionContext = $this->_action->getContext();
		$this->assertSame($context, $actionContext);
	}

	public function testCredentials()
	{
		$this->assertNull($this->_action->getCredentials());
	}

	public function testgetDefaultViewName()
	{
		$this->assertEquals('Input', $this->_action->getDefaultViewName());
	}

	public function testhandleError()
	{
		$this->assertEquals('Error', $this->_action->handleError(new WebRequest()));
	}

	public function testisSecure()
	{
		$this->assertFalse($this->_action->isSecure());
	}

	public function testvalidate()
	{
		$this->assertTrue($this->_action->validate(new WebRequest()));
	}
}
?>