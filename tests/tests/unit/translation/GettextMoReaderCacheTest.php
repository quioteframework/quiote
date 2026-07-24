<?php

use Quiote\Exception\QuioteException;
use Quiote\Testing\UnitTestCase;
use Quiote\Translation\Gettext\GettextMoReader;

/**
 * Covers GettextMoReader::readFile() parsing plus the process-lifetime catalog
 * cache added so a FrankenPHP worker reads and unpacks each .mo file once rather
 * than on every request (GettextTranslator drops its own per-request catalog on
 * reset()). The cache is keyed by path+mtime+size, so a changed file is re-read.
 */
class GettextMoReaderCacheTest extends UnitTestCase
{
	/** @var list<string> */
	private array $filesToDelete = [];

	#[\Override]
	protected function tearDown(): void
	{
		foreach ($this->filesToDelete as $file) {
			@unlink($file);
		}
		parent::tearDown();
	}

	/**
	 * Write a minimal valid little-endian .mo file with the given msgid => msgstr map.
	 * @param array<string, string> $pairs
	 */
	private function writeMo(string $path, array $pairs): void
	{
		$ids = array_keys($pairs);
		sort($ids, SORT_STRING);
		$n = count($ids);

		$headerSize = 28;
		$offset = $headerSize + $n * 8 * 2;

		$origData = '';
		$origTable = '';
		foreach ($ids as $id) {
			$len = strlen($id);
			$origTable .= pack('VV', $len, $offset);
			$origData .= $id . "\0";
			$offset += $len + 1;
		}
		$transData = '';
		$transTable = '';
		foreach ($ids as $id) {
			$str = $pairs[$id];
			$len = strlen($str);
			$transTable .= pack('VV', $len, $offset);
			$transData .= $str . "\0";
			$offset += $len + 1;
		}

		$mo = pack('V', 0x950412de)
			. pack('V', 0)
			. pack('V', $n)
			. pack('V', $headerSize)
			. pack('V', $headerSize + $n * 8)
			. pack('V', 0)
			. pack('V', 0)
			. $origTable . $transTable . $origData . $transData;

		file_put_contents($path, $mo);
	}

	private function tempPath(): string
	{
		$path = tempnam(sys_get_temp_dir(), 'motest_') . '.mo';
		$this->filesToDelete[] = $path;
		return $path;
	}

	public function testReadsCatalogEntries(): void
	{
		$path = $this->tempPath();
		$this->writeMo($path, ['Hello' => 'Hej', 'Goodbye' => 'Hej da']);

		$data = GettextMoReader::readFile($path);

		$this->assertSame('Hej', $data['Hello']);
		$this->assertSame('Hej da', $data['Goodbye']);
	}

	public function testRepeatedReadReturnsIdenticalData(): void
	{
		$path = $this->tempPath();
		$this->writeMo($path, ['One' => 'Ett', 'Two' => 'Tva']);

		$first = GettextMoReader::readFile($path);
		$second = GettextMoReader::readFile($path);

		$this->assertSame($first, $second);
	}

	public function testCacheIsInvalidatedWhenFileContentChanges(): void
	{
		$path = $this->tempPath();
		$this->writeMo($path, ['Color' => 'Farg']);
		$this->assertSame('Farg', GettextMoReader::readFile($path)['Color']);

		// Rewrite the same path with different (larger) content: the size
		// component of the cache key changes, so the stale entry is bypassed.
		$this->writeMo($path, ['Color' => 'Kulor', 'Shape' => 'Form']);
		$reread = GettextMoReader::readFile($path);

		$this->assertSame('Kulor', $reread['Color']);
		$this->assertSame('Form', $reread['Shape']);
	}

	public function testUnreadableFileThrows(): void
	{
		$this->expectException(QuioteException::class);
		GettextMoReader::readFile(sys_get_temp_dir() . '/quiote_no_such_catalog_' . uniqid() . '.mo');
	}
}
