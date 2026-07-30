<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\BaseConfigHandler;
use Quiote\Exception\ConfigurationException;

class MyBaseConfigHandler extends BaseConfigHandler
{
	/**
	 * @param mixed $code
	 */
	public function callGenerate($code, ?string $path = null): string
	{
		return $this->generate($code, $path);
	}
}

class BaseConfigHandlerTest extends PhpUnitTestCase
{
	private MyBaseConfigHandler $handler;

	#[\Override]
	public function setUp(): void
	{
		$this->handler = new MyBaseConfigHandler();
	}

	public function testGenerateAcceptsString(): void
	{
		$result = $this->handler->callGenerate('return 1;', '/tmp/foo.xml');
		$this->assertStringContainsString('return 1;', $result);
		$this->assertStringContainsString('/tmp/foo.xml', $result);
	}

	public function testGenerateAcceptsArrayOfStrings(): void
	{
		$result = $this->handler->callGenerate(['$a = 1;', '$b = 2;']);
		$this->assertStringContainsString("\$a = 1;\n\$b = 2;", $result);
	}

	public function testGenerateThrowsWhenCodeIsNotStringOrArray(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->handler->callGenerate(42);
	}

	public function testGenerateThrowsWhenArrayContainsNonStringEntry(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->handler->callGenerate(['return 1;', 42]);
	}
}
