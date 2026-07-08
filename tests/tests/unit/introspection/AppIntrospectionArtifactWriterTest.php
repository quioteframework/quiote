<?php

use Quiote\Introspection\AppIntrospectionArtifactWriter;
use Quiote\Testing\PhpUnitTestCase;

final class AppIntrospectionArtifactWriterTest extends PhpUnitTestCase
{
	private string $target;

	protected function setUp(): void
	{
		parent::setUp();
		$this->target = sys_get_temp_dir() . '/quiote-introspection-test-' . uniqid('', true) . '/nested/app.json';
	}

	protected function tearDown(): void
	{
		$dir = dirname($this->target, 2);
		if (is_dir($dir)) {
			array_map('unlink', glob($dir . '/nested/*') ?: []);
			@rmdir($dir . '/nested');
			@rmdir($dir);
		}
		parent::tearDown();
	}

	public function testWritesValidJsonAndCreatesMissingDirectories(): void
	{
		(new AppIntrospectionArtifactWriter())->write(['_schema_version' => 1, 'routes' => []], $this->target);

		$this->assertFileExists($this->target);
		$decoded = json_decode((string) file_get_contents($this->target), true, flags: JSON_THROW_ON_ERROR);
		$this->assertSame(1, $decoded['_schema_version']);
		$this->assertSame([], $decoded['routes']);
	}

	public function testOverwritesAnExistingArtifactAtomically(): void
	{
		$writer = new AppIntrospectionArtifactWriter();
		$writer->write(['_schema_version' => 1], $this->target);
		$writer->write(['_schema_version' => 2], $this->target);

		$decoded = json_decode((string) file_get_contents($this->target), true, flags: JSON_THROW_ON_ERROR);
		$this->assertSame(2, $decoded['_schema_version']);

		// No leftover .tmp-* files from the write-then-rename.
		$leftovers = glob(dirname($this->target) . '/*.tmp-*') ?: [];
		$this->assertSame([], $leftovers);
	}

	public function testThrowsWhenTheTargetDirectoryCannotBeCreated(): void
	{
		// A regular file can never be treated as a directory to create children under.
		$blocker = sys_get_temp_dir() . '/quiote-introspection-blocker-' . uniqid('', true);
		file_put_contents($blocker, 'x');

		try {
			$this->expectException(RuntimeException::class);
			(new AppIntrospectionArtifactWriter())->write(['a' => 1], $blocker . '/app.json');
		} finally {
			@unlink($blocker);
		}
	}
}
