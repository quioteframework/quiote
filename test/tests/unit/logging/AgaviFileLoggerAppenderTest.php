<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Logging\AgaviFileLoggerAppender;
use Agavi\Logging\AgaviLoggerMessage;
use Agavi\Logging\AgaviPassthruLoggerLayout;
// Removed deprecated #[RunClassInSeparateProcess] (PHPUnit 13 migration)
class AgaviFileLoggerAppenderTest extends AgaviUnitTestCase
{
	private $_file, $_fa;

	#[\Override]
    public function setUp(): void
	{
		$this->_file = tempnam(AgaviConfig::get('core.cache_dir', sys_get_temp_dir()), 'AgaviFileLoggerAppenderTest');
		unlink($this->_file);
		$this->_fa = new AgaviFileLoggerAppender();
		$this->_fa->initialize($this->getContext(), ['file'=>$this->_file]);
		$this->_fa->setLayout(new AgaviPassthruLoggerLayout());
	}

	#[\Override]
    public function tearDown(): void
	{
		@unlink($this->_file);
	}

	public function testInitialize()
	{
		$this->assertFalse(file_exists($this->_file));
		$this->_fa->write(new AgaviLoggerMessage('my message'));
		$this->assertTrue(file_exists($this->_file));
		$this->_fa->shutdown();
	}

	public function testWrite()
	{
		$this->_fa->write(new AgaviLoggerMessage('my message'));
		$this->assertMatchesRegularExpression('/my message/', file_get_contents($this->_file));
		$this->_fa->shutdown();
	}

	/*
	public function testshutdown()
	{
		// how do you test if the file is still open? - flock() and then attempt to remove it (??)
	}
	*/

}

?>