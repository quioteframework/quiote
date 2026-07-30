<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Config\FactoryConfigHandler;

/**
 * Proves a factories file written as plain PHP compiles through the exact
 * same FactoryConfigHandler as factories.xml -- second handler migrated,
 * phase 2.
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

	/**
	 * FactoryConfigHandler::executeArray() declares a precise array shape
	 * for $config, but FormatDriverRegistry::load() only knows how to
	 * promise array<string, mixed> since it is shared across every config
	 * type. Narrow the registry's generic result back into the shape the
	 * handler expects.
	 * @param array<string, mixed> $config
	 * @return array<string, array{class: string|null, params: array<mixed>}>
	 */
	private function shapeFactoryConfig(array $config): array
	{
		$shaped = [];

		foreach ($config as $name => $factory) {
			self::assertIsArray($factory);
			self::assertArrayHasKey('class', $factory);
			$class = $factory['class'];
			self::assertTrue($class === null || is_string($class));
			self::assertArrayHasKey('params', $factory);
			self::assertIsArray($factory['params']);
			$shaped[$name] = [
				'class' => $class,
				'params' => $factory['params'],
			];
		}

		return $shaped;
	}

	public function testPhpArrayFactoriesFileCompilesThroughFactoryConfigHandler(): void
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

		$config = $this->shapeFactoryConfig($registry->load($this->dir . '/factories.php', 'test'));
		$code = $handler->executeArray($config, $this->dir . '/factories.php');

		$this->assertStringContainsString('$this->databaseManager = new Quiote\Database\DatabaseManager();', $code);
		$this->assertStringContainsString("\$this->factories['validation_manager'] = array (", $code);
		$this->assertStringContainsString('$this->shutdownSequence = [', $code);
	}

	public function testMissingRequiredFactoryThrowsRegardlessOfSourceFormat(): void
	{
		file_put_contents($this->dir . '/factories.php', "<?php\nreturn ['response' => ['class' => 'Quiote\\Response\\WebResponse', 'params' => []]];\n");

		$handler = new FactoryConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $this->shapeFactoryConfig($registry->load($this->dir . '/factories.php', 'test'));

		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$handler->executeArray($config, $this->dir . '/factories.php');
	}
}
?>
