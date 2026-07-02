<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\DatabaseConfigHandler;

/**
 * Proves a databases file written as plain PHP compiles through the exact
 * same DatabaseConfigHandler as databases.xml, including its
 * undefined-default-database validation -- seventh handler migrated per
 * docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 2.
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

	public function testPhpArrayDatabasesFileCompilesThroughDatabaseConfigHandler()
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

		$config = $registry->load($this->dir . '/databases.php', 'test');
		$code = $handler->executeArray($config, $this->dir . '/databases.php');

		$this->assertStringContainsString('new Quiote\Database\PdoDatabase();', $code);
		$this->assertStringContainsString("\$this->defaultDatabaseName = 'main';", $code);
	}

	public function testUndefinedDefaultDatabaseThrowsRegardlessOfSourceFormat()
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
		$config = $registry->load($this->dir . '/databases.php', 'test');

		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$handler->executeArray($config, $this->dir . '/databases.php');
	}
}
?>
