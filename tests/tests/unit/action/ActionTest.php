<?php

use Quiote\Action\Action;
use Quiote\Request\RequestDataHolder;
use Quiote\Request\WebRequest;
use Quiote\Util\ParameterHolder;
use Quiote\Testing\UnitTestCase;

class SampleAction extends Action {
	public function execute(ParameterHolder $parameters): void
	{
	}
}

class ActionTest extends UnitTestCase
{
	private SampleAction $_action;

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
		unset($this->_action);
	}

	public function testgetContext(): void
	{
		$context = $this->getContext();
		$actionContext = $this->_action->getContext();
		$this->assertSame($context, $actionContext);
	}

	public function testCredentials(): void
	{
		$this->assertNull($this->_action->getCredentials());
	}

	public function testgetDefaultViewName(): void
	{
		$this->assertEquals('Input', $this->_action->getDefaultViewName());
	}

	public function testhandleError(): void
	{
		$this->assertEquals('Error', $this->_action->handleError(new WebRequest()));
	}

	public function testisSecure(): void
	{
		$this->assertFalse($this->_action->isSecure());
	}

	public function testvalidate(): void
	{
		$this->assertTrue($this->_action->validate(new WebRequest()));
	}
}
?>
