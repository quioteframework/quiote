<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\RbacDefinitionConfigHandler;
use Quiote\Config\TestSuitesConfigHandler;

/**
 * Proves a PHP-array file compiles through RbacDefinitionConfigHandler and
 * TestSuitesConfigHandler exactly like the XML equivalents do -- third and
 * fourth handlers migrated, phase 2.
 */
class RbacAndTestSuitesFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'rbts_');
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
	 * RbacDefinitionConfigHandler::executeArray() declares a precise array
	 * shape for $config, but FormatDriverRegistry::load() only knows how to
	 * promise array<string, mixed> since it is shared across every config
	 * type. Narrow the registry's generic result back into the shape the
	 * handler expects.
	 * @param array<string, mixed> $config
	 * @return array<string, array{parent: ?string, permissions: array<int, mixed>}>
	 */
	private function shapeRbacConfig(array $config): array
	{
		$shaped = [];

		foreach ($config as $name => $role) {
			self::assertIsArray($role);
			self::assertArrayHasKey('parent', $role);
			$parent = $role['parent'];
			self::assertTrue($parent === null || is_string($parent));
			self::assertArrayHasKey('permissions', $role);
			self::assertIsArray($role['permissions']);
			$shaped[$name] = [
				'parent' => $parent,
				'permissions' => array_values($role['permissions']),
			];
		}

		return $shaped;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, array<string, mixed>>
	 */
	private function shapeTestSuitesConfig(array $config): array
	{
		$shaped = [];

		foreach ($config as $name => $suite) {
			self::assertIsArray($suite);
			$shaped[$name] = $suite;
		}

		return $shaped;
	}

	public function testRbacPhpArrayFileCompilesAndEvaluatesToTheSameShapeAsXml(): void
	{
		file_put_contents($this->dir . '/rbac.php', <<<'PHP'
<?php
return [
    'guest' => ['parent' => null, 'permissions' => ['photos.list']],
    'member' => ['parent' => 'guest', 'permissions' => ['photos.rate']],
];
PHP);

		$handler = new RbacDefinitionConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $this->shapeRbacConfig($registry->load($this->dir . '/rbac.php', 'test'));
		$declaration = $handler->executeArray($config, $this->dir . '/rbac.php');

		self::assertIsArray($declaration);
		$this->assertSame($config, $declaration);
		$this->assertSame('guest', $declaration['member']['parent']);
	}

	public function testTestSuitesPhpArrayFileCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/testsuites.php', <<<'PHP'
<?php
return [
    'unit' => ['class' => 'TestSuite', 'base' => 'tests/', 'includes' => ['unit/*'], 'excludes' => [], 'testfiles' => []],
];
PHP);

		$handler = new TestSuitesConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $this->shapeTestSuitesConfig($registry->load($this->dir . '/testsuites.php', 'test'));
		$declaration = $handler->executeArray($config, $this->dir . '/testsuites.php');

		$this->assertSame($config, $declaration);
		$this->assertStringContainsString("'unit'", var_export($declaration, true));
		$this->assertStringContainsString('TestSuite', var_export($declaration, true));
	}
}
?>
