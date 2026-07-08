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
	protected ?MyTestConfigHandler $ch = null;
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

	public function testGetValidationFile(): void
	{
		$ch = $this->ch;
		if ($ch === null) {
			$this->fail('setUp() did not initialize the config handler under test.');
		}
		$this->assertSame('MyValidationFile.mvf', $ch->getValidationFile());
	}

}
