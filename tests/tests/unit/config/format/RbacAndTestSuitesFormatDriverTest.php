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

	public function testRbacPhpArrayFileCompilesAndEvaluatesToTheSameShapeAsXml()
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

		$config = $registry->load($this->dir . '/rbac.php', 'test');
		$code = $handler->executeArray($config, $this->dir . '/rbac.php');

		$evaluated = eval(substr($code, strlen('<?php')));
		$this->assertSame($config, $evaluated);
		$this->assertSame('guest', $evaluated['member']['parent']);
	}

	public function testTestSuitesPhpArrayFileCompilesThroughTheSameHandler()
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

		$config = $registry->load($this->dir . '/testsuites.php', 'test');
		$code = $handler->executeArray($config, $this->dir . '/testsuites.php');

		$this->assertStringContainsString("'unit'", $code);
		$this->assertStringContainsString('TestSuite', $code);
	}
}
?>
