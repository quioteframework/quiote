<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\DatabaseConfigHandler;

/**
 * Proves a databases file written as plain PHP compiles through the exact
 * same DatabaseConfigHandler as databases.xml, including its
 * undefined-default-database validation -- seventh handler migrated,
 * phase 2.
 */
class DatabaseConfigHandlerFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'dbchfd_');
		unlink($this->dir);
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	/**
	 * DatabaseConfigHandler::executeArray() declares a precise array shape
	 * for $config, but FormatDriverRegistry::load() only knows how to
	 * promise array<string, mixed> since it is shared across every config
	 * type. Narrow the registry's generic result back into the shape the
	 * handler expects, the same way a real caller relying on a fixed schema
	 * would.
	 * @param array<string, mixed> $config
	 * @return array{default?: string|null, databases?: array<string, array{class: string, parameters: array<int|string, mixed>}>}
	 */
	private function shapeDatabaseConfig(array $config): array
	{
		$shaped = [];

		if (array_key_exists('default', $config)) {
			$default = $config['default'];
			self::assertTrue($default === null || is_string($default));
			$shaped['default'] = $default;
		}

		if (array_key_exists('databases', $config)) {
			$databases = $config['databases'];
			self::assertIsArray($databases);
			$shapedDatabases = [];
			foreach ($databases as $name => $database) {
				self::assertIsString($name);
				self::assertIsArray($database);
				self::assertArrayHasKey('class', $database);
				self::assertIsString($database['class']);
				self::assertArrayHasKey('parameters', $database);
				self::assertIsArray($database['parameters']);
				$shapedDatabases[$name] = [
					'class' => $database['class'],
					'parameters' => $database['parameters'],
				];
			}
			$shaped['databases'] = $shapedDatabases;
		}

		return $shaped;
	}

	public function testPhpArrayDatabasesFileCompilesThroughDatabaseConfigHandler(): void
	{
		file_put_contents($this->dir . '/databases.php', <<<'PHP'
<?php
return [
    'default' => 'main',
    'databases' => [
        'main' => ['class' => 'Quiote\Database\PdoDatabase', 'parameters' => ['dsn' => 'sqlite::memory:']],
    ],
];
PHP);

		$handler = new DatabaseConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $this->shapeDatabaseConfig($registry->load($this->dir . '/databases.php', 'test'));
		$code = $handler->executeArray($config, $this->dir . '/databases.php');

		$this->assertStringContainsString('new Quiote\Database\PdoDatabase();', $code);
		$this->assertStringContainsString("\$this->defaultDatabaseName = 'main';", $code);
	}

	public function testUndefinedDefaultDatabaseThrowsRegardlessOfSourceFormat(): void
	{
		file_put_contents($this->dir . '/databases.php', <<<'PHP'
<?php
return [
    'default' => 'does_not_exist',
    'databases' => [
        'main' => ['class' => 'Quiote\Database\PdoDatabase', 'parameters' => []],
    ],
];
PHP);

		$handler = new DatabaseConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $this->shapeDatabaseConfig($registry->load($this->dir . '/databases.php', 'test'));

		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$handler->executeArray($config, $this->dir . '/databases.php');
	}
}
?>
