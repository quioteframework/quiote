<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\MiddlewareConfigHandler;
use Quiote\Config\Format\FormatDriverRegistry;
use Quiote\Exception\ConfigurationException;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * middleware.xml compiles to the same MiddlewareConfigRegistry contributions
 * whether the source is XML, a plain PHP array, or YAML, and the guard
 * against unauthorized framework-middleware overrides fires regardless of
 * source format.
 */
class MiddlewareConfigHandlerFormatDriverTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'mchfd_');
		unlink($this->dir);
		mkdir($this->dir);
		MiddlewareConfigRegistry::reset();
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		MiddlewareConfigRegistry::reset();
		Config::remove(MiddlewareConfigRegistry::OVERRIDE_SETTING);
		parent::tearDown();
	}

	/**
	 * FormatDriverRegistry::load() returns array<string,mixed> generically
	 * (the shape is format-driver-agnostic), but the fixtures in this test
	 * always load a plain list of middleware entries -- re-validate that
	 * real invariant here so MiddlewareConfigHandler::executeArray() gets
	 * the list<array{...}> shape it actually requires.
	 * @param array<string, mixed> $config
	 * @return list<array{class: string, phase?: ?string, priority?: ?int, before?: ?string, after?: ?string, enabled?: ?bool, override_framework?: bool}>
	 */
	private function asMiddlewareList(array $config): array
	{
		$result = [];
		foreach ($config as $entry) {
			if (!is_array($entry) || !isset($entry['class']) || !is_string($entry['class'])) {
				throw new \LogicException('Expected a list of middleware entries with a string "class" key.');
			}
			$result[] = [
				'class' => $entry['class'],
				'phase' => isset($entry['phase']) && is_string($entry['phase']) ? $entry['phase'] : null,
				'priority' => isset($entry['priority']) && is_int($entry['priority']) ? $entry['priority'] : null,
				'before' => isset($entry['before']) && is_string($entry['before']) ? $entry['before'] : null,
				'after' => isset($entry['after']) && is_string($entry['after']) ? $entry['after'] : null,
				'enabled' => isset($entry['enabled']) ? (bool) $entry['enabled'] : null,
				'override_framework' => isset($entry['override_framework']) && (bool) $entry['override_framework'],
			];
		}
		return $result;
	}

	private function includeCompiled(string $code): void
	{
		$file = tempnam($this->dir, 'compiled_');
		rename($file, $file .= '.php');
		file_put_contents($file, $code);
		include $file;
		unlink($file);
	}

	private function assertRegistryHasHealthzEntry(): void
	{
		$entries = MiddlewareConfigRegistry::all();
		$this->assertCount(1, $entries);
		$this->assertSame(MiddlewareConfigHandlerFormatDriverTestFixtureMiddleware::class, $entries[0]['class']);
		$this->assertSame('pre_routing', $entries[0]['phase']);
		$this->assertSame('Quiote\\Middleware\\SessionMiddleware', $entries[0]['before']);
	}

	public function testXmlMiddlewareFileCompiles(): void
	{
		file_put_contents($this->dir . '/middleware.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/middleware/1.1">
    <ae:configuration>
        <use class="MiddlewareConfigHandlerFormatDriverTestFixtureMiddleware" phase="pre_routing" before="Quiote\Middleware\SessionMiddleware" />
    </ae:configuration>
</ae:configurations>
XML);

		$handler = new MiddlewareConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/middleware.xml', 'test');
		$code = $handler->executeArray($this->asMiddlewareList($config), $this->dir . '/middleware.xml');

		$this->includeCompiled($code);
		$this->assertRegistryHasHealthzEntry();
	}

	public function testPhpArrayMiddlewareFileCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/middleware.php', <<<'PHP'
<?php
return [
    ['class' => 'MiddlewareConfigHandlerFormatDriverTestFixtureMiddleware', 'phase' => 'pre_routing', 'before' => 'Quiote\Middleware\SessionMiddleware'],
];
PHP);

		$handler = new MiddlewareConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/middleware.php', 'test');
		$code = $handler->executeArray($this->asMiddlewareList($config), $this->dir . '/middleware.php');

		$this->includeCompiled($code);
		$this->assertRegistryHasHealthzEntry();
	}

	public function testYamlMiddlewareFileCompilesThroughTheSameHandler(): void
	{
		file_put_contents($this->dir . '/middleware.yaml', <<<'YAML'
- class: 'MiddlewareConfigHandlerFormatDriverTestFixtureMiddleware'
  phase: pre_routing
  before: 'Quiote\Middleware\SessionMiddleware'
YAML);

		$handler = new MiddlewareConfigHandler();
		$handler->initialize(null, []);
		$registry = FormatDriverRegistry::forHandler($handler);
		$config = $registry->load($this->dir . '/middleware.yaml', 'test');
		$code = $handler->executeArray($this->asMiddlewareList($config), $this->dir . '/middleware.yaml');

		$this->includeCompiled($code);
		$this->assertRegistryHasHealthzEntry();
	}

	public function testGuardBlocksUnauthorizedFrameworkOverrideRegardlessOfSourceFormat(): void
	{
		Config::set(MiddlewareConfigRegistry::OVERRIDE_SETTING, false, true);

		$handler = new MiddlewareConfigHandler();
		$handler->initialize(null, []);
		$code = $handler->executeArray([
			['class' => ErrorHandlingMiddleware::class, 'enabled' => false],
		], 'in-memory-test');

		$this->expectException(ConfigurationException::class);
		$this->includeCompiled($code);
	}

	public function testGuardAllowsFrameworkOverrideWhenBothAuthorizationsPresent(): void
	{
		Config::set(MiddlewareConfigRegistry::OVERRIDE_SETTING, true, true);

		$handler = new MiddlewareConfigHandler();
		$handler->initialize(null, []);
		$code = $handler->executeArray([
			['class' => ErrorHandlingMiddleware::class, 'enabled' => false, 'override_framework' => true],
		], 'in-memory-test');

		$this->includeCompiled($code);

		$entries = MiddlewareConfigRegistry::all();
		$this->assertCount(1, $entries);
		$this->assertSame(ErrorHandlingMiddleware::class, $entries[0]['class']);
		$this->assertFalse($entries[0]['enabled']);
	}
}

final class MiddlewareConfigHandlerFormatDriverTestFixtureMiddleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		return $handler->handle($request);
	}
}
