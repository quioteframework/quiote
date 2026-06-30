<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest;

class SampleView extends AgaviView
{
	public function execute(AgaviWebRequest $rd) {}
}

class AgaviViewTest extends AgaviUnitTestCase
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
		$descriptor = new \Agavi\Execution\ActionDescriptor('Test','Test','GET','html', false);
		$init = new \Agavi\Execution\LightweightActionInitContext(
			$ctx,
			$descriptor->module,
			$descriptor->action,
			$descriptor->method,
			$descriptor->outputType,
			new AgaviWebRequest(),
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