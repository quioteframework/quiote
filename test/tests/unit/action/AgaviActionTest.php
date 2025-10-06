<?php

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Request\AgaviWebRequest;
use Agavi\Util\AgaviParameterHolder;
use Agavi\Testing\AgaviUnitTestCase;

class SampleAction extends AgaviAction {
	public function execute(AgaviParameterHolder $parameters)
	{
	}
}

class AgaviActionTest extends AgaviUnitTestCase
{
	private $_action = null;

	public function setUp(): void
	{
		$this->_action = new SampleAction();
		// Initialize action with lightweight initialization context (descriptor-less)
		$controller = $this->getContext()->getController();
		// Use synthetic descriptor (module/action need not exist for initialization tests)
		$descriptor = new \Agavi\Execution\ActionDescriptor('Foo','Bar','GET','html', false);
		$lw = new \Agavi\Execution\LightweightActionInitContext(
			$this->getContext(),
			$descriptor->module,
			$descriptor->action,
			$descriptor->method,
			$descriptor->outputType,
			new AgaviWebRequest(),
			$controller->getGlobalResponse()
		);
		$this->_action->initialize($lw);
	}

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
		$this->assertEquals('Error', $this->_action->handleError(new AgaviWebRequest()));
	}

	public function testisSecure()
	{
		$this->assertFalse($this->_action->isSecure());
	}

	public function testvalidate()
	{
		$this->assertTrue($this->_action->validate(new AgaviWebRequest()));
	}
}
?>