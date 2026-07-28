<?php

use PHPUnit\Framework\Attributes\After;
use Quiote\Console\Application;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * `openapi:generate` through the CLI harness. Runs against the
 * "mcp-action-tool-test" context, whose routing is pure #[Route] attribute
 * routing over the sandbox modules -- so the document describes the same
 * McpActionTool fixtures OpenApiGeneratorTest uses, but reached the way a real
 * invocation reaches them (the configured routing service, not a hand-built
 * RouteDefinition).
 */
final class OpenapiGenerateCommandTest extends PhpUnitTestCase
{
	/** @var string[] */
	private array $writtenFiles = [];

	#[After]
	public function removeWrittenFiles(): void
	{
		foreach ($this->writtenFiles as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		$this->writtenFiles = [];
	}

	private function tester(): CommandTester
	{
		$application = new Application();
		return new CommandTester($application->find('openapi:generate'));
	}

	private function tempFile(string $extension): string
	{
		$path = sys_get_temp_dir() . '/quiote-openapi-test-' . bin2hex(random_bytes(6)) . '.' . $extension;
		$this->writtenFiles[] = $path;
		return $path;
	}

	/**
	 * Walks into the decoded document, asserting each step exists and is itself
	 * a map -- a decoded JSON/YAML document is `mixed` all the way down.
	 * @param array<mixed, mixed> $node
	 * @return array<mixed, mixed>
	 */
	private function at(array $node, string|int ...$path): array
	{
		$cursor = $node;
		foreach ($path as $key) {
			$this->assertArrayHasKey($key, $cursor, 'missing key: ' . $key);
			$next = $cursor[$key];
			$this->assertIsArray($next, 'not a map: ' . $key);
			$cursor = $next;
		}

		return $cursor;
	}

	/**
	 * @param array<mixed, mixed> $document
	 * @return array<mixed, mixed>
	 */
	private function operation(array $document, string $path, string $verb): array
	{
		return $this->at($document, 'paths', $path, $verb);
	}

	/** @return array<mixed, mixed> */
	private function decode(string $encoded, bool $yaml = false): array
	{
		$document = $yaml ? Yaml::parse($encoded) : json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
		$this->assertIsArray($document);

		return $document;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{0: int, 1: array<mixed, mixed>}
	 */
	private function generate(array $options = []): array
	{
		$tester = $this->tester();
		$exitCode = $tester->execute(['--context' => 'mcp-action-tool-test', '--env' => 'testing'] + $options);

		return [$exitCode, $this->decode($tester->getDisplay())];
	}

	public function testWritesAJsonDocumentToStdoutByDefault(): void
	{
		[$exitCode, $document] = $this->generate();

		$this->assertSame(0, $exitCode);
		$this->assertSame('3.1.0', $document['openapi'] ?? null);
		$this->assertArrayHasKey('/mcp-action-tool-test/greet/{name}', $this->at($document, 'paths'));
		$this->assertSame(
			['type' => 'string', 'minLength' => 2, 'maxLength' => 50],
			$this->at($this->operation($document, '/mcp-action-tool-test/greet/{name}', 'get'), 'parameters', 0, 'schema'),
		);
	}

	public function testInfoAndServersCanBeOverriddenPerRun(): void
	{
		[, $document] = $this->generate([
			'--title' => 'Sandbox API',
			'--api-version' => '4.5.6',
			'--server' => ['https://a.example.test', 'https://b.example.test'],
		]);

		$this->assertSame(['title' => 'Sandbox API', 'version' => '4.5.6'], $this->at($document, 'info'));
		$this->assertSame(
			[['url' => 'https://a.example.test'], ['url' => 'https://b.example.test']],
			$this->at($document, 'servers'),
		);
	}

	public function testModuleFilterRestrictsTheDocument(): void
	{
		[, $document] = $this->generate(['--module' => ['McpActionTool']]);

		$paths = $this->at($document, 'paths');
		foreach (array_keys($paths) as $path) {
			$this->assertIsString($path);
			foreach (array_keys($this->at($paths, $path)) as $verb) {
				$this->assertIsString($verb);
				$this->assertSame('McpActionTool', $this->at($this->operation($document, $path, $verb), 'x-quiote')['module'] ?? null, $path);
			}
		}
		$this->assertArrayHasKey('/mcp-action-tool-test/fluent', $paths);
	}

	public function testExcludePatternLeavesRoutesOut(): void
	{
		[, $document] = $this->generate(['--exclude' => ['mcp_action_tool_test.*']]);

		$this->assertArrayNotHasKey('/mcp-action-tool-test/greet/{name}', $this->at($document, 'paths'));
	}

	public function testDocblockProseCanBeSuppressed(): void
	{
		[, $withProse] = $this->generate();
		$this->assertArrayHasKey('summary', $this->operation($withProse, '/mcp-action-tool-test/fluent', 'post'));

		[, $without] = $this->generate(['--no-docblocks' => true]);
		$this->assertArrayNotHasKey('summary', $this->operation($without, '/mcp-action-tool-test/fluent', 'post'));
	}

	public function testWritesToAFileAndReportsThePathCount(): void
	{
		$path = $this->tempFile('json');
		$tester = $this->tester();
		$exitCode = $tester->execute(['--context' => 'mcp-action-tool-test', '--env' => 'testing', '--output' => $path]);

		$this->assertSame(0, $exitCode);
		$this->assertStringContainsString('Wrote ' . $path, $tester->getDisplay());
		$document = $this->decode((string) file_get_contents($path));
		$this->assertSame('3.1.0', $document['openapi'] ?? null);
	}

	public function testYamlIsInferredFromTheOutputExtension(): void
	{
		$path = $this->tempFile('yaml');
		$tester = $this->tester();
		$tester->execute(['--context' => 'mcp-action-tool-test', '--env' => 'testing', '--output' => $path]);

		$document = $this->decode((string) file_get_contents($path), yaml: true);
		$this->assertSame('3.1.0', $document['openapi'] ?? null);
		$this->assertArrayHasKey('/mcp-action-tool-test/greet/{name}', $this->at($document, 'paths'));
		$this->assertSame(
			['type' => 'string'],
			$this->at($this->operation($document, '/mcp-action-tool-test/greet/{name}', 'get'), 'responses', 200, 'content', 'text/html', 'schema'),
		);
	}

	public function testExplicitYamlFormatWritesYamlToStdout(): void
	{
		$tester = $this->tester();
		$tester->execute(['--context' => 'mcp-action-tool-test', '--env' => 'testing', '--format' => 'yml']);

		$document = $this->decode($tester->getDisplay(), yaml: true);
		$this->assertSame('3.1.0', $document['openapi'] ?? null);
	}

	public function testRejectsAnUnknownFormat(): void
	{
		$tester = $this->tester();
		$exitCode = $tester->execute(['--context' => 'mcp-action-tool-test', '--env' => 'testing', '--format' => 'toml']);

		$this->assertSame(1, $exitCode);
		$this->assertStringContainsString('Unknown --format', $tester->getDisplay());
	}

	public function testFailsWhenTheOutputPathIsNotWritable(): void
	{
		$tester = $this->tester();
		$exitCode = @$tester->execute([
			'--context' => 'mcp-action-tool-test',
			'--env' => 'testing',
			'--output' => sys_get_temp_dir() . '/quiote-openapi-missing-dir/spec.json',
		]);

		$this->assertSame(1, $exitCode);
		$this->assertStringContainsString('Could not write', $tester->getDisplay());
	}
}
