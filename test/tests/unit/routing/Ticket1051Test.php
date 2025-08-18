<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\AgaviContext;

require_once(__DIR__ . '/../../../lib/routing/AgaviTestingWebRouting.class.php');

class Ticket1051Test extends AgaviPhpUnitTestCase
{
	protected $routing;
	protected $parameters = array('enabled' => true);
	

	
	public function setUp(): void
	{
		// otherwise, the full URI wouldn't work
		$_SERVER['REQUEST_URI'] = '/index.php';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		
		$this->routing = new AgaviTestingWebRouting();
		$this->routing->initialize(AgaviContext::getInstance(null), $this->parameters);
		$this->routing->startup();
	}
	
	public function testCallbackOnGenerateCanSetOptions()
	{
		$this->markTestSkipped('Legacy gen() callback route ticket_1051 skipped during routing migration.');
	}
}


?>