<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\AgaviContext;

class Ticket1294Test extends AgaviPhpUnitTestCase
{
	protected $routing;
	protected $parameters = ['enabled' => true];
	
	/**
	 * Constructs a test case with the given name.
	 *
	 * @param  string $name
	 * @param  array  $data
	 * @param  string $dataName
	 */
	
	#[\Override]
    public function setUp(): void
	{
		// otherwise, the full URI wouldn't work
		$_SERVER['REQUEST_URI'] = '/index.php';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		
		$this->routing = new AgaviTestingWebRouting();
		$this->routing->initialize(AgaviContext::getInstance(null), $this->parameters);
		$this->routing->startup();
	}
	
	public function testQueryStringParametersCanBeUnsetUsingNull()
	{
		$this->routing->setInput('/ticket_1294');
		$this->routing->setInputParameters(['foo' => 'bar']);
		$url = $this->routing->gen(null, ['foo' => null]);
		$this->assertEquals('/index.php/ticket_1294', $url);
	}
}


?>