<?php

use Quiote\Testing\UnitTestCase;
use Quiote\View\View;
use Quiote\Request\WebRequest;

class SampleView extends View
{
	public function execute(WebRequest $rd): void {}
}

class ViewTest extends UnitTestCase
{
	private SampleView $_v;

	#[\Override]
    public function setUp(): void
	{
		$ctx = $this->getContext();
		$ctx->initialize();

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
	}

	public function testInitialize(): void
	{
		$ctx = $this->getContext();
		$v = $this->_v;

		$ctx_test = $v->getContext();
		$this->assertSame($ctx, $ctx_test);
	}


}
?>
