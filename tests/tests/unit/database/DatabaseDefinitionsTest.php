<?php

use Quiote\Database\DatabaseDefinitions;
use Quiote\Database\PdoDatabase;
use Quiote\Exception\ConfigurationException;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Reading a *generated* file, so the failure worth guarding against is a cache compiled by a
 * different version of the framework. Every rejection here says so, rather than letting a type
 * error surface from somewhere inside DatabaseManager::initialize().
 */
class DatabaseDefinitionsTest extends PhpUnitTestCase
{
	/** @return array{databases: array<string, array{class: string, parameters: array<string, mixed>}>, default: string} */
	private function valid(): array
	{
		return [
			'databases' => [
				'main' => ['class' => PdoDatabase::class, 'parameters' => ['dsn' => 'sqlite::memory:']],
			],
			'default' => 'main',
		];
	}

	public function testAValidDeclarationIsRead(): void
	{
		$definitions = DatabaseDefinitions::fromCompiled($this->valid());

		$this->assertSame('main', $definitions->default);
		$this->assertSame(
			['class' => PdoDatabase::class, 'parameters' => ['dsn' => 'sqlite::memory:']],
			$definitions->databases['main'],
		);
	}

	public function testDeclarationOrderIsPreserved(): void
	{
		$definitions = DatabaseDefinitions::fromCompiled([
			'databases' => [
				'first' => ['class' => PdoDatabase::class, 'parameters' => []],
				'second' => ['class' => PdoDatabase::class, 'parameters' => []],
			],
			'default' => 'second',
		]);

		$this->assertSame(['first', 'second'], array_keys($definitions->databases));
	}

	public function testMissingParametersDefaultToNone(): void
	{
		$definitions = DatabaseDefinitions::fromCompiled([
			'databases' => ['main' => ['class' => PdoDatabase::class]],
			'default' => 'main',
		]);

		$this->assertSame([], $definitions->databases['main']['parameters']);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function dataNotADeclaration(): array
	{
		return [
			'null, as an empty include would give' => [null],
			'a string' => ['return $this->databases;'],
			'an array with no databases key' => [['default' => 'main']],
			'an array with no default key' => [['databases' => []]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataNotADeclaration')]
	public function testSomethingThatIsNotADeclarationIsRejectedWithAnUpgradeHint(mixed $compiled): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('clear the configuration cache');

		DatabaseDefinitions::fromCompiled($compiled);
	}

	public function testAMalformedShapeIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('malformed');

		DatabaseDefinitions::fromCompiled(['databases' => 'not-an-array', 'default' => 'main']);
	}

	public function testAConnectionWithNoClassIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('no class');

		DatabaseDefinitions::fromCompiled([
			'databases' => ['main' => ['parameters' => []]],
			'default' => 'main',
		]);
	}

	/**
	 * The case a renamed adapter class produces. Naming it beats a "class not found" from a `new`
	 * three frames deeper.
	 */
	public function testAClassThatDoesNotExistIsRejectedByName(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('NoSuchDatabaseAdapter');

		DatabaseDefinitions::fromCompiled([
			'databases' => ['main' => ['class' => 'NoSuchDatabaseAdapter', 'parameters' => []]],
			'default' => 'main',
		]);
	}

	public function testAClassThatIsNotADatabaseIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('is not a');

		DatabaseDefinitions::fromCompiled([
			'databases' => ['main' => ['class' => \stdClass::class, 'parameters' => []]],
			'default' => 'main',
		]);
	}

	/**
	 * The handler already refuses this at compile time; the declaration refuses it again, because a
	 * default naming nothing would make getDatabase() answer null for every unnamed call.
	 */
	public function testADefaultNamingAnUndeclaredConnectionIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('does not declare');

		DatabaseDefinitions::fromCompiled([
			'databases' => ['main' => ['class' => PdoDatabase::class, 'parameters' => []]],
			'default' => 'absent',
		]);
	}

	/**
	 * Database::initialize() takes named parameters, so a positional one is refused rather than
	 * silently dropped by a coercion.
	 */
	public function testAPositionalParameterKeyIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('non-string parameter key');

		DatabaseDefinitions::fromCompiled([
			'databases' => ['main' => ['class' => PdoDatabase::class, 'parameters' => ['positional']]],
			'default' => 'main',
		]);
	}
}
