<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Config\AgaviConfigHandler;

class MyTestConfigHandler extends AgaviConfigHandler
{
	public function execute($config, $context = null)
	{
		return '';
	}
}

class AgaviConfigHandlerTest extends AgaviPhpUnitTestCase
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
