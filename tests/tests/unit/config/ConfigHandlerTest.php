<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\ConfigHandler;

class MyTestConfigHandler extends ConfigHandler
{
	public function execute($config, $context = null)
	{
		return '';
	}
}

class ConfigHandlerTest extends PhpUnitTestCase
{
	protected $ch = null;
	#[\Override]
    public function setUp(): void
	{
		$this->ch = new MyTestConfigHandler();
		$this->ch->initialize('MyValidationFile.mvf');
	}

	#[\Override]
    public function tearDown(): void
	{
		$this->ch = null;
	}

	public function testGetValidationFile()
	{
		$this->assertSame('MyValidationFile.mvf', $this->ch->getValidationFile());
	}

}
