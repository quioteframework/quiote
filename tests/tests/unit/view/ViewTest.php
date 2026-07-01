<?php

use Quiote\Testing\UnitTestCase;
use Quiote\View\View;
use Quiote\Request\WebRequest;

class SampleView extends View
{
	public function execute(WebRequest $rd) {}
}

class ViewTest extends UnitTestCase
{
	private 
		$_v = null, 
		$_r = null;

	#[\Override]
    public function setUp(): void
	{
		$ctx = $this->getContext();
		$ctx->initialize();
		$request = $ctx->getRequest();

		$this->_v = new SampleView();
		$controller = $ctx->getController();
		$descriptor = new \Quiote\Execution\ActionDescriptor('Test','Test','GET','html', false);
		$init = new \Quiote\Execution\LightweightActionInitContext(
			$ctx,
			$descriptor->module,
			$descriptor->action,
			$descriptor->method,
			$descriptor->outputType,
			new WebRequest(),
			$controller->getGlobalResponse()
		);
		$this->_v->initialize($init);
		$this->_r = $controller->getGlobalResponse();
	}

	public function testInitialize()
	{
		$ctx = $this->getContext();
		$v = $this->_v;

		$ctx_test = $v->getContext();
		$this->assertSame($ctx, $ctx_test);
	}


}
?>