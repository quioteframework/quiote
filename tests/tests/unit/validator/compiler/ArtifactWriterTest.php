<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Validator\Compiler\ArtifactDriftChecker;
use Quiote\Validator\Compiler\EmittedArtifact;
use Quiote\Validator\Compiler\FilesystemArtifactWriter;

class ArtifactWriterTest extends PhpUnitTestCase
{
	private string $dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'awt_');
		unlink($this->dir);
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->dir);
		parent::tearDown();
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}

	public function testWriteCreatesFileWithExactSource()
	{
		$artifact = EmittedArtifact::fromSource('<?php // hello', 'demo.php');
		$target = $this->dir . '/demo.php';

		(new FilesystemArtifactWriter())->write($artifact, $target);

		$this->assertFileExists($target);
		$this->assertSame('<?php // hello', file_get_contents($target));
	}

	public function testWriteCreatesMissingParentDirectories()
	{
		$artifact = EmittedArtifact::fromSource('<?php // nested', 'demo.php');
		$target = $this->dir . '/Module/Validate/Action.generated.php';

		(new FilesystemArtifactWriter())->write($artifact, $target);

		$this->assertFileExists($target);
	}

	public function testWriteLeavesNoTemporaryFilesBehind()
	{
		$artifact = EmittedArtifact::fromSource('<?php // clean', 'demo.php');
		$target = $this->dir . '/demo.php';

		(new FilesystemArtifactWriter())->write($artifact, $target);

		$entries = array_values(array_diff(scandir($this->dir), ['.', '..']));
		$this->assertSame(['demo.php'], $entries);
	}

	public function testWriteIsIdempotentByteForByte()
	{
		$artifact = EmittedArtifact::fromSource('<?php // stable', 'demo.php');
		$target = $this->dir . '/demo.php';

		$writer = new FilesystemArtifactWriter();
		$writer->write($artifact, $target);
		$firstMtime = filemtime($target);
		clearstatcache(true, $target);
		$writer->write($artifact, $target);

		$this->assertSame('<?php // stable', file_get_contents($target));
	}

	public function testDriftCheckerReportsMismatchWhenFileMissing()
	{
		$artifact = EmittedArtifact::fromSource('<?php // new', 'demo.php');
		$result = (new ArtifactDriftChecker())->check($artifact, $this->dir . '/missing.php');

		$this->assertFalse($result->matches);
		$this->assertNull($result->existingChecksum);
		$this->assertSame($artifact->checksum, $result->expectedChecksum);
	}

	public function testDriftCheckerReportsMatchWhenContentIsIdentical()
	{
		$artifact = EmittedArtifact::fromSource('<?php // same', 'demo.php');
		$target = $this->dir . '/demo.php';
		(new FilesystemArtifactWriter())->write($artifact, $target);

		$result = (new ArtifactDriftChecker())->check($artifact, $target);
		$this->assertTrue($result->matches);
		$this->assertSame($artifact->checksum, $result->existingChecksum);
	}

	public function testDriftCheckerReportsMismatchWhenCommittedFileWasHandEdited()
	{
		$artifact = EmittedArtifact::fromSource('<?php // original', 'demo.php');
		$target = $this->dir . '/demo.php';
		(new FilesystemArtifactWriter())->write($artifact, $target);
		file_put_contents($target, '<?php // someone hand-edited this');

		$result = (new ArtifactDriftChecker())->check($artifact, $target);
		$this->assertFalse($result->matches);
		$this->assertNotSame($artifact->checksum, $result->existingChecksum);
	}

	public function testDriftCheckerNeverWritesAnything()
	{
		$artifact = EmittedArtifact::fromSource('<?php // untouched', 'demo.php');
		$target = $this->dir . '/never-created.php';

		(new ArtifactDriftChecker())->check($artifact, $target);

		$this->assertFileDoesNotExist($target);
	}
}
?>
