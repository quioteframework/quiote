<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Logging\AgaviLoggerLayout;
use Agavi\Logging\AgaviLoggerMessage;

class SampleLayout extends AgaviLoggerLayout
{
	public function format(AgaviLoggerMessage $message)
	{
	}
}

class AgaviLoggerLayoutTest extends AgaviUnitTestCase
{
	public function testGetSetLayout()
	{
		$layout = new SampleLayout;
		$this->assertNull($layout->getLayout());
		$layout->setLayout('something');
		$this->assertEquals('something', $layout->getLayout());
	}
}

?>