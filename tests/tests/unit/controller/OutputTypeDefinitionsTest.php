<?php

use Quiote\Controller\OutputTypeDefinitions;
use Quiote\Exception\ConfigurationException;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Reading a *generated* file, so the failure worth guarding against is a cache compiled by a
 * different version of the framework -- and every rejection here says so, instead of letting a type
 * error surface from inside OutputType::initialize().
 */
class OutputTypeDefinitionsTest extends PhpUnitTestCase
{
	/** @return array<string, mixed> */
	private function declaration(string $name = 'html'): array
	{
		return [
			'outputTypes' => [
				$name => [
					'parameters' => ['content_type' => 'text/html'],
					'renderers' => ['php' => ['instance' => null, 'parameters' => []]],
					'defaultRenderer' => 'php',
					'layouts' => ['default' => ['layers' => [], 'parameters' => []]],
					'defaultLayout' => 'default',
					'exceptionTemplate' => 'error',
				],
			],
			'default' => $name,
		];
	}

	public function testAValidDeclarationIsRead(): void
	{
		$definitions = OutputTypeDefinitions::fromCompiled($this->declaration());

		$this->assertSame('html', $definitions->default);
		$this->assertSame(['content_type' => 'text/html'], $definitions->outputTypes['html']['parameters']);
		$this->assertSame('php', $definitions->outputTypes['html']['defaultRenderer']);
		$this->assertSame('default', $definitions->outputTypes['html']['defaultLayout']);
		$this->assertSame('error', $definitions->outputTypes['html']['exceptionTemplate']);
	}

	public function testDeclarationOrderIsPreserved(): void
	{
		$definitions = OutputTypeDefinitions::fromCompiled([
			'outputTypes' => [
				'html' => ['parameters' => []],
				'json' => ['parameters' => []],
			],
			'default' => 'json',
		]);

		$this->assertSame(['html', 'json'], array_keys($definitions->outputTypes));
	}

	/**
	 * A configuration may declare output types without electing a default; getOutputType() falls
	 * back on its own terms, so null must survive rather than being rejected.
	 */
	public function testANullDefaultIsLegal(): void
	{
		$definitions = OutputTypeDefinitions::fromCompiled([
			'outputTypes' => ['html' => ['parameters' => []]],
			'default' => null,
		]);

		$this->assertNull($definitions->default);
		$this->assertArrayHasKey('html', $definitions->outputTypes);
	}

	public function testAbsentOptionalKeysBecomeEmptyArraysAndNulls(): void
	{
		$definitions = OutputTypeDefinitions::fromCompiled([
			'outputTypes' => ['html' => []],
			'default' => 'html',
		]);

		$declaration = $definitions->outputTypes['html'];
		$this->assertSame([], $declaration['parameters']);
		$this->assertSame([], $declaration['renderers']);
		$this->assertSame([], $declaration['layouts']);
		$this->assertNull($declaration['defaultRenderer']);
		$this->assertNull($declaration['defaultLayout']);
		$this->assertNull($declaration['exceptionTemplate']);
	}

	/** @return array<string, array{0: mixed}> */
	public static function dataNotADeclaration(): array
	{
		return [
			'null, as an empty include would give' => [null],
			'a string' => ['$this->outputTypes = [];'],
			'no outputTypes key' => [['default' => 'html']],
			'no default key' => [['outputTypes' => []]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataNotADeclaration')]
	public function testSomethingThatIsNotADeclarationIsRejectedWithAnUpgradeHint(mixed $compiled): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('clear the configuration cache');

		OutputTypeDefinitions::fromCompiled($compiled);
	}

	public function testAMalformedShapeIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('malformed');

		OutputTypeDefinitions::fromCompiled(['outputTypes' => 'nope', 'default' => null]);
	}

	public function testADefaultNamingAnUndeclaredOutputTypeIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('does not declare');

		OutputTypeDefinitions::fromCompiled([
			'outputTypes' => ['html' => ['parameters' => []]],
			'default' => 'absent',
		]);
	}

	/**
	 * A renderer or layout entry that is not itself a map is dropped rather than handed on as a
	 * scalar, which OutputType would then have to guard against on every read.
	 */
	public function testANonMapRendererOrLayoutEntryIsDropped(): void
	{
		$definitions = OutputTypeDefinitions::fromCompiled([
			'outputTypes' => [
				'html' => [
					'renderers' => ['good' => ['instance' => null], 'bad' => 'not-a-map'],
					'layouts' => ['good' => ['layers' => []], 'bad' => 42],
				],
			],
			'default' => 'html',
		]);

		$this->assertSame(['good'], array_keys($definitions->outputTypes['html']['renderers']));
		$this->assertSame(['good'], array_keys($definitions->outputTypes['html']['layouts']));
	}

	/**
	 * These feed name lookups, so a non-string could never match one and is treated as absent.
	 */
	public function testANonStringDefaultRendererOrLayoutBecomesNull(): void
	{
		$definitions = OutputTypeDefinitions::fromCompiled([
			'outputTypes' => [
				'html' => ['defaultRenderer' => 42, 'defaultLayout' => ['array'], 'exceptionTemplate' => false],
			],
			'default' => 'html',
		]);

		$declaration = $definitions->outputTypes['html'];
		$this->assertNull($declaration['defaultRenderer']);
		$this->assertNull($declaration['defaultLayout']);
		$this->assertNull($declaration['exceptionTemplate']);
	}
}
