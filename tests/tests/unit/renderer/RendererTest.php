<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Renderer\Renderer;
use Quiote\View\TemplateLayer;

class TRTestSampleRenderer extends Renderer
{
	public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
	{
	}
}

class RendererTest extends UnitTestCase
{
	protected $_r = null, $_v = null;

	#[\Override]
    public function setUp(): void
	{
		$this->_r = new TRTestSampleRenderer();
		$this->_r->initialize($this->getContext());
	}

	public function testGetContext()
	{
		$c1 = $this->getContext();
		$c2 = $this->_r->getContext();
		$this->assertSame($c1, $c2);
	}
}
?>