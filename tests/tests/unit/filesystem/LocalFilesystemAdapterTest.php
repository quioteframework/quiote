<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Filesystem\FileNotFoundStorageException;
use Quiote\Filesystem\FilesystemStorageException;
use Quiote\Filesystem\LocalFilesystemAdapter;

final class LocalFilesystemAdapterTest extends TestCase
{
    private string $root;
    private LocalFilesystemAdapter $adapter;

    #[Before]
    protected function setUpAdapter(): void
    {
        $this->root = sys_get_temp_dir() . '/quiote-fs-test-' . uniqid('', true);
        $this->adapter = new LocalFilesystemAdapter($this->root);
    }

    #[After]
    protected function tearDownAdapter(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }

    public function testConstructorCreatesRootDirectory(): void
    {
        $this->assertDirectoryExists($this->root);
    }

    public function testConstructorThrowsOnEmptyRoot(): void
    {
        $this->expectException(FilesystemStorageException::class);
        new LocalFilesystemAdapter('');
    }

    public function testConstructorThrowsWhenRootCannotBeCreated(): void
    {
        // A file, not a directory, can never become a valid mkdir target beneath it.
        $blocker = sys_get_temp_dir() . '/quiote-fs-blocker-' . uniqid('', true);
        file_put_contents($blocker, 'x');
        try {
            $this->expectException(FilesystemStorageException::class);
            new LocalFilesystemAdapter($blocker . '/nested');
        } finally {
            unlink($blocker);
        }
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $this->adapter->write('report.csv', 'a,b,c');

        $this->assertSame('a,b,c', $this->adapter->read('report.csv'));
    }

    public function testWriteCreatesParentDirectories(): void
    {
        $this->adapter->write('nested/dir/report.csv', 'data');

        $this->assertSame('data', $this->adapter->read('nested/dir/report.csv'));
    }

    public function testReadMissingFileThrows(): void
    {
        $this->expectException(FileNotFoundStorageException::class);
        $this->adapter->read('missing.txt');
    }

    public function testExistsTrueAndFalse(): void
    {
        $this->assertFalse($this->adapter->exists('report.csv'));

        $this->adapter->write('report.csv', 'data');

        $this->assertTrue($this->adapter->exists('report.csv'));
    }

    public function testDeleteRemovesFile(): void
    {
        $this->adapter->write('report.csv', 'data');
        $this->adapter->delete('report.csv');

        $this->assertFalse($this->adapter->exists('report.csv'));
    }

    public function testDeleteOfMissingFileIsSilentNoOp(): void
    {
        $this->adapter->delete('never-existed.txt');
        $this->addToAssertionCount(1);
    }

    public function testSizeReturnsByteCount(): void
    {
        $this->adapter->write('report.csv', 'abcde');

        $this->assertSame(5, $this->adapter->size('report.csv'));
    }

    public function testSizeOfMissingFileThrows(): void
    {
        $this->expectException(FileNotFoundStorageException::class);
        $this->adapter->size('missing.txt');
    }

    public function testLastModifiedReturnsRecentTimestamp(): void
    {
        $before = time();
        $this->adapter->write('report.csv', 'data');
        $after = time();

        $lastModified = $this->adapter->lastModified('report.csv');

        $this->assertGreaterThanOrEqual($before, $lastModified->getTimestamp());
        $this->assertLessThanOrEqual($after, $lastModified->getTimestamp());
    }

    public function testLastModifiedOfMissingFileThrows(): void
    {
        $this->expectException(FileNotFoundStorageException::class);
        $this->adapter->lastModified('missing.txt');
    }

    public function testListContentsOfEmptyDirectoryIsEmpty(): void
    {
        $this->assertSame([], $this->adapter->listContents());
    }

    public function testListContentsOfMissingSubdirectoryIsEmpty(): void
    {
        $this->assertSame([], $this->adapter->listContents('does-not-exist'));
    }

    public function testListContentsReturnsRelativePathsSorted(): void
    {
        $this->adapter->write('b.txt', '2');
        $this->adapter->write('a.txt', '1');

        $this->assertSame(['a.txt', 'b.txt'], $this->adapter->listContents());
    }

    public function testListContentsIsNonRecursive(): void
    {
        $this->adapter->write('top.txt', '1');
        $this->adapter->write('nested/deep.txt', '2');

        $this->assertSame(['nested', 'top.txt'], $this->adapter->listContents());
    }

    public function testPathTraversalWithDotDotIsRejected(): void
    {
        $this->expectException(FilesystemStorageException::class);
        $this->adapter->read('../escape.txt');
    }

    public function testAbsolutePathIsRejected(): void
    {
        $this->expectException(FilesystemStorageException::class);
        $this->adapter->read('/etc/passwd');
    }

    public function testEmptyPathIsRejected(): void
    {
        $this->expectException(FilesystemStorageException::class);
        $this->adapter->read('');
    }
}
