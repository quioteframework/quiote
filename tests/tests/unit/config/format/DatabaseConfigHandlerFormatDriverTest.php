<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\DatabaseConfigHandler;

/**
 * Proves a databases file written as plain PHP compiles through the exact
 * same DatabaseConfigHandler as databases.xml, including its
 * undefined-default-database validation and the two patterns that stand in
 * for XML's environment-filtered `<ae:configuration>` blocks.
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

		$this->assertStringContainsString("'class' => 'Quiote\\\\Database\\\\PdoDatabase'", var_export($code, true));
		$this->assertStringContainsString("'default' => 'main'", var_export($code, true));
	}

	/**
	 * A PHP-array config carries no equivalent of XML's
	 * `<ae:configuration environment="...">` filtering, so an author who wants
	 * an environment-dependent value reaches for one of two documented
	 * patterns. The first is the `%core.environment%` directive, expanded by
	 * DirectiveExpander like any other directive before the handler runs.
	 */
	public function testEnvironmentDirectiveExpandsInPhpArrayDatabasesFile(): void
	{
		file_put_contents($this->dir . '/databases.php', <<<'PHP'
<?php
return [
    'default' => 'main',
    'databases' => [
        'main' => [
            'class' => 'Quiote\Database\PdoDatabase',
            'parameters' => ['dsn' => 'sqlite:/var/db/app-%core.environment%.sqlite'],
        ],
    ],
];
PHP);

		$handler = new DatabaseConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $this->shapeDatabaseConfig($registry->load($this->dir . '/databases.php', 'testing'));

		$this->assertSame(
			[
				'main' => [
					'class' => 'Quiote\Database\PdoDatabase',
					'parameters' => ['dsn' => 'sqlite:/var/db/app-' . Config::getString('core.environment') . '.sqlite'],
				],
			],
			$config['databases'] ?? []
		);
	}

	/**
	 * The second pattern: branch inside the returned array. The environment is
	 * readable through Config by the time a config file is loaded, because
	 * Quiote::bootstrap() sets core.environment read-only before any config is
	 * compiled. The compiled cache name embeds the environment, so each
	 * environment keeps its own compiled result.
	 */
	public function testEnvironmentBranchingInPhpArrayDatabasesFileSelectsPerEnvironmentValues(): void
	{
		file_put_contents($this->dir . '/databases.php', <<<'PHP'
<?php
$isTest = str_starts_with((string) \Quiote\Config\Config::getNullableString('core.environment'), 'test');

return [
    'default' => 'main',
    'databases' => [
        'main' => [
            'class' => 'Quiote\Database\PdoDatabase',
            'parameters' => ['dsn' => $isTest ? 'sqlite::memory:' : 'pgsql:host=localhost;dbname=app'],
        ],
    ],
];
PHP);

		$handler = new DatabaseConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $this->shapeDatabaseConfig($registry->load($this->dir . '/databases.php', 'testing'));

		$this->assertStringStartsWith('test', Config::getString('core.environment'));
		$this->assertSame(
			[
				'main' => [
					'class' => 'Quiote\Database\PdoDatabase',
					'parameters' => ['dsn' => 'sqlite::memory:'],
				],
			],
			$config['databases'] ?? []
		);
	}

	/**
	 * The file-per-environment variant of the same idea: `parent` is resolved
	 * through the format registry after directive expansion, so naming
	 * `%core.environment%` in the reference picks the file for the active
	 * environment. Parent values sit *under* the child's own, so the child
	 * still overrides whatever it restates.
	 */
	public function testEnvironmentNamedParentSelectsThePerEnvironmentFile(): void
	{
		$environment = Config::getString('core.environment');

		file_put_contents($this->dir . '/databases.php', <<<'PHP'
<?php
return [
    'parent'  => __DIR__ . '/databases.%core.environment%.php',
    'default' => 'main',
];
PHP);

		file_put_contents($this->dir . '/databases.' . $environment . '.php', <<<'PHP'
<?php
return [
    'default' => 'overridden_by_child',
    'databases' => [
        'main' => ['class' => 'Quiote\Database\PdoDatabase', 'parameters' => ['dsn' => 'sqlite::memory:']],
    ],
];
PHP);

		$handler = new DatabaseConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $this->shapeDatabaseConfig($registry->load($this->dir . '/databases.php', $environment));

		$this->assertSame('main', $config['default'] ?? null);
		$this->assertSame(
			[
				'main' => [
					'class' => 'Quiote\Database\PdoDatabase',
					'parameters' => ['dsn' => 'sqlite::memory:'],
				],
			],
			$config['databases'] ?? []
		);

		// And it compiles: the environment-selected parent supplied the
		// database the child's `default` names.
		$this->assertNotSame('', $handler->executeArray($config, $this->dir . '/databases.php'));
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
