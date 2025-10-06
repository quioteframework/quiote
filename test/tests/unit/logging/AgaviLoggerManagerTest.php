<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Logging\AgaviPassthruLoggerLayout;
use Agavi\Logging\AgaviFileLoggerAppender;
use Agavi\Logging\AgaviLogger;
use Agavi\Logging\AgaviLoggerMessage;

class AgaviLoggerManagerTest extends AgaviUnitTestCase
{
	private
		$_context = null,
		$_lm = null,
		$_logfile = '',
		$_logfile2 = '',
		$_pl = null,
		$_fa = null,
		$_fa2 = null,
		$_l = null,
		$_l2 = null;

	public function setUp(): void
	{
		// Ensure framework bootstrap occurs when running this test in isolation.
		parent::setUp();
		// Force-enable logging in case a prior test disabled it globally.
		\Agavi\Config\AgaviConfig::set('core.use_logging', true);
		$this->_context = $this->getContext();
		$this->_lm = $this->_context->getLoggerManager();
		// If still null (context created earlier while logging disabled), lazily create and inject a logger manager.
		if ($this->_lm === null) {
			$lm = new \Agavi\Logging\AgaviLoggerManager();
			$lm->initialize($this->_context, []);
			$rc = new ReflectionClass($this->_context);
			if ($rc->hasProperty('loggerManager')) {
				$prop = $rc->getProperty('loggerManager');
				$prop->setAccessible(true);
				$prop->setValue($this->_context, $lm);
			}
			$this->_lm = $lm;
		}
		// Use tempnam to obtain unique paths, then remove if present to start with a clean slate.
		$this->_logfile = tempnam('', 'logtest');
		$this->_logfile2 = tempnam('', 'logtest2');
		if (is_file($this->_logfile)) {
			unlink($this->_logfile);
		}
		if (is_file($this->_logfile2)) {
			unlink($this->_logfile2);
		}
		$this->_pl = new AgaviPassthruLoggerLayout;
		$this->_fa = new AgaviFileLoggerAppender;
		$this->_fa->initialize($this->_context, array('file' => $this->_logfile));
		$this->_fa->setLayout($this->_pl);
		$this->_fa2 = new AgaviFileLoggerAppender;
		$this->_fa2->initialize($this->_context, array('file' => $this->_logfile2));
		$this->_fa2->setLayout($this->_pl);
		$this->_l = new AgaviLogger;
		$this->_l->setLevel(AgaviLogger::INFO);
		$this->_l->setAppender('fa', $this->_fa);
		$this->_l2 = new AgaviLogger;
		$this->_l2->setLevel(AgaviLogger::DEBUG | AgaviLogger::INFO);
		$this->_l2->setAppender('fa2', $this->_fa2);
	}

	public function tearDown(): void
	{
		$this->_lm->shutdown();
		if (is_file($this->_logfile)) {
			unlink($this->_logfile);
		}
		if (is_file($this->_logfile2)) {
			unlink($this->_logfile2);
		}
		$this->_lm = null;
		$this->_context = null;
		parent::tearDown();
	}

	public function testGetLoggerNames()
	{
		$this->assertEquals(array(), $this->_lm->getLoggerNames());
		$this->_lm->setLogger('logfile', $this->_l);
		$this->assertEquals(array('logfile'), $this->_lm->getLoggerNames());
		$this->_lm->setLogger('logfile2', $this->_l2);
		$this->assertEquals(array('logfile', 'logfile2'), $this->_lm->getLoggerNames());
	}

	public function testGetLogger()
	{
		$this->_lm->setLogger($this->_lm->getDefaultLoggerName(), $this->_l);
		$this->assertEquals($this->_l, $this->_lm->getLogger());
		$this->_lm->setLogger('logfile2', $this->_l2);
		$this->assertEquals($this->_l, $this->_lm->getLogger('default'));
		$this->assertEquals($this->_l2, $this->_lm->getLogger('logfile2'));
	}

	public function testLoggerLogLevel()
	{
		$this->assertEquals(AgaviLogger::INFO, $this->_l->getLevel());
		$this->assertEquals(AgaviLogger::DEBUG | AgaviLogger::INFO, $this->_l2->getLevel());
	}

	public function testLog()
	{
		$this->_lm->setLogger('logfile', $this->_l);
		$this->_lm->setLogger('logfile2', $this->_l2);
		$this->assertFalse(file_exists($this->_logfile));
		$this->assertFalse(file_exists($this->_logfile2));

		//this should be logged by both
		$this->_lm->log(new AgaviLoggerMessage('simple info message', AgaviLogger::INFO));
		$this->assertMatchesRegularExpression('/simple info message/', file_get_contents($this->_logfile));
		$this->assertMatchesRegularExpression('/simple info message/', file_get_contents($this->_logfile2));

		//this should be logged only by l2
		$this->_lm->log(new AgaviLoggerMessage('simple debug message', AgaviLogger::DEBUG));
		$this->assertDoesNotMatchRegularExpression('/simple debug message/', file_get_contents($this->_logfile));
		$this->assertMatchesRegularExpression('/simple debug message/', file_get_contents($this->_logfile2));

		//this should be logged only by l2
		$this->_lm->log('simple debug message two', AgaviLogger::DEBUG);
		$this->assertDoesNotMatchRegularExpression('/simple debug message two/', file_get_contents($this->_logfile));
		$this->assertMatchesRegularExpression('/simple debug message two/', file_get_contents($this->_logfile2));

		//this should be logged only by l
		$this->_lm->log('simple debug message three', $this->_l);
		$this->assertMatchesRegularExpression('/simple debug message three/', file_get_contents($this->_logfile));
		$this->assertDoesNotMatchRegularExpression('/simple debug message three/', file_get_contents($this->_logfile2));

		//this should be logged only by l
		$this->_lm->log(new AgaviLoggerMessage('simple info message four', AgaviLogger::INFO), $this->_l);
		$this->assertMatchesRegularExpression('/simple info message four/', file_get_contents($this->_logfile));
		$this->assertDoesNotMatchRegularExpression('/simple info message four/', file_get_contents($this->_logfile2));
	}

}

?>