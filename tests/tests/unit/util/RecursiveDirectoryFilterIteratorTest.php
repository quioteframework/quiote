<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\RecursiveDirectoryFilterIterator;

class RecursiveDirectoryFilterIteratorTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rdfi_test_' . uniqid('', true);
		mkdir($this->dir);
		mkdir($this->dir . '/sub');
		file_put_contents($this->dir . '/a.txt', 'x');
		file_put_contents($this->dir . '/a.log', 'x');
		file_put_contents($this->dir . '/sub/b.txt', 'x');
	}

	protected function tearDown(): void
	{
		$this->removeDirectory($this->dir);
		parent::tearDown();
	}

	private function removeDirectory(string $dir): void
	{
		if(!is_dir($dir)) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach($items as $item) {
			if(!$item instanceof SplFileInfo) {
				continue;
			}
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}

	public function testOnlyMatchingFilesAreIncludedAndDirectoriesAreAlwaysTraversed(): void
	{
		$inner = new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS);
		$filter = new RecursiveDirectoryFilterIterator($inner, ['\.txt$']);
		$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

		$paths = [];
		foreach($iterator as $info) {
			if(!$info instanceof SplFileInfo) {
				continue;
			}
			$paths[] = str_replace($this->dir . '/', '', $info->getPathname());
		}
		sort($paths);

		$this->assertSame(['a.txt', 'sub', 'sub/b.txt'], $paths);
	}

	public function testExcludedNamesAreFilteredOutEvenWhenIncluded(): void
	{
		$inner = new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS);
		$filter = new RecursiveDirectoryFilterIterator($inner, [], ['a.txt']);
		$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

		$paths = [];
		foreach($iterator as $info) {
			if(!$info instanceof SplFileInfo) {
				continue;
			}
			$paths[] = str_replace($this->dir . '/', '', $info->getPathname());
		}
		sort($paths);

		$this->assertSame(['a.log', 'sub', 'sub/b.txt'], $paths);
	}

	public function testCurrentFileInfoThrowsWhenInnerIteratorYieldsPathnameStrings(): void
	{
		$inner = new RecursiveDirectoryIterator(
			$this->dir,
			FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS
		);
		$filter = new RecursiveDirectoryFilterIterator($inner);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Expected the decorated RecursiveDirectoryIterator to yield SplFileInfo instances; got string.');

		foreach($filter as $ignored) {
			// Iterating triggers accept(), which is where the exception is thrown.
			unset($ignored);
		}
	}
}
