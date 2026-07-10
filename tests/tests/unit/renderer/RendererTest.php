<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Renderer\Renderer;
use Quiote\View\TemplateLayer;

class TRTestSampleRenderer extends Renderer
{
	public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = []): string
	{
		return '';
	}
}

class RendererTest extends UnitTestCase
{
	protected TRTestSampleRenderer $_r;

	#[\Override]
    public function setUp(): void
	{
		$this->_r = new TRTestSampleRenderer();
		$this->_r->initialize($this->getContext());
	}

	public function testGetContext(): void
	{
		$c1 = $this->getContext();
		$c2 = $this->_r->getContext();
		$this->assertSame($c1, $c2);
	}

	public function testGetStarterTemplateDefaultsToNull(): void
	{
		$this->assertNull($this->_r->getStarterTemplate());
	}
}
?>