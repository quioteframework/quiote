<?php

use Agavi\Logging\AgaviLoggerMessage;
use Agavi\Logging\AgaviPassthruLoggerLayout;
use Agavi\Testing\AgaviUnitTestCase;

class AgaviPassthruLoggerLayoutTest extends AgaviUnitTestCase
{
	public function testFormat()
	{
		$layout = new AgaviPassthruLoggerLayout();
		$message = new AgaviLoggerMessage('something');
		$this->assertEquals('something', $layout->format($message));
	}
}

?>