<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\AgaviContext;
use Agavi\Routing\AgaviWebRouting;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class AgaviWebRoutingServerCasesTest extends AgaviUnitTestCase
{
	protected $_r = null;

	protected $_SERVER = array();
	protected $_ENV = array();
	protected $_GET = array();

	protected $export = array();

	public function setExport($export)
	{
		$this->export = $export;
	}

	public function setUp(): void
	{
		$this->markTestSkipped('Legacy AgaviWebRouting server cases skipped (AgaviWebRouting removed).');
		$this->_SERVER = $_SERVER;
		$this->_ENV = $_ENV;
		$this->_GET = $_GET;
		AgaviConfig::set('core.use_routing', true);
	}

	public static function loadTestCases()
	{
		$retval = array();
		
		$d = dir(__DIR__ . '/cases/');
		while(false !== ($entry = $d->read())) {
			if(preg_match('#.*\\.case\\.php#i', $entry))
			{
				$cases = include($d->path . $entry);
				foreach($cases as $case) {
					$retval[$entry . ': ' . $case['message']] = array($case);
				}
			}
		}
		$d->close();
		
		return $retval;
	}

	#[RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\DataProvider('loadTestCases')]
	public function testCases($export)
	{
		return; // skipped
	}


	public function tearDown(): void
	{
		// no-op
	}

}

?>