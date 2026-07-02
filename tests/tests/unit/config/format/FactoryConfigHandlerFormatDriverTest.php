<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\FactoryConfigHandler;

/**
 * Proves a factories file written as plain PHP compiles through the exact
 * same FactoryConfigHandler as factories.xml -- second handler migrated
 * per docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 2.
 */
class FactoryConfigHandlerFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'fchfd_');
		unlink($this->dir);
		mkdir($this->dir);
		Config::set('core.use_translation', true, true);
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	public function testPhpArrayFactoriesFileCompilesThroughFactoryConfigHandler()
	{
		file_put_contents($this->dir . '/factories.php', <<<'PHP'
<?php
return [
    'validation_manager' => ['class' => 'Quiote\Validator\ValidationManager', 'params' => ['mode' => 'strict']],
    'response' => ['class' => 'Quiote\Response\WebResponse', 'params' => []],
    'database_manager' => ['class' => 'Quiote\Database\DatabaseManager', 'params' => []],
    'translation_manager' => ['class' => 'Quiote\Translation\TranslationManager', 'params' => []],
    'routing' => ['class' => 'Sandbox\App\Routing\SandboxRouting', 'params' => []],
    'request' => ['class' => 'Quiote\Request\WebRequest', 'params' => []],
    'controller' => ['class' => 'Quiote\Controller\Controller', 'params' => []],
    'storage' => ['class' => 'Quiote\Storage\NullStorage', 'params' => []],
    'user' => ['class' => 'Quiote\User\SecurityUser', 'params' => []],
];
PHP);

		$handler = new FactoryConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);

		$config = $registry->load($this->dir . '/factories.php', 'test');
		$code = $handler->executeArray($config, $this->dir . '/factories.php');

		$this->assertStringContainsString('$this->databaseManager = new Quiote\Database\DatabaseManager();', $code);
		$this->assertStringContainsString("\$this->factories['validation_manager'] = array (", $code);
		$this->assertStringContainsString('$this->shutdownSequence = [', $code);
	}

	public function testMissingRequiredFactoryThrowsRegardlessOfSourceFormat()
	{
		file_put_contents($this->dir . '/factories.php', "<?php\nreturn ['response' => ['class' => 'Quiote\\Response\\WebResponse', 'params' => []]];\n");

		$handler = new FactoryConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/factories.php', 'test');

		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$handler->executeArray($config, $this->dir . '/factories.php');
	}
}
?>
