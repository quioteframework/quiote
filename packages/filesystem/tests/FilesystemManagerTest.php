<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemAdapterInterface;
use Quiote\Filesystem\FilesystemConfig;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Filesystem\FilesystemManager;
use Quiote\Filesystem\ListableFilesystemInterface;
use Quiote\Filesystem\LocalFilesystemAdapter;

final class FilesystemManagerTest extends TestCase
{
    private string $root;

    #[After]
    protected function resetRegistry(): void
    {
        FilesystemDriverRegistry::reset();
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
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

    private function makeManager(string $defaultDisk = 'local'): FilesystemManager
    {
        $this->root = sys_get_temp_dir() . '/quiote-fs-manager-test-' . uniqid('', true);
        $root = $this->root;
        $container = new Container();
        $container->set(LocalFilesystemAdapter::class, static fn() => new LocalFilesystemAdapter($root));

        return new FilesystemManager($container, new FilesystemConfig($defaultDisk, $this->root));
    }

    public function testDiskResolvesDefaultAlias(): void
    {
        $manager = $this->makeManager();

        $this->assertInstanceOf(LocalFilesystemAdapter::class, $manager->disk());
    }

    public function testDiskResolvesExplicitAlias(): void
    {
        $manager = $this->makeManager();

        $this->assertInstanceOf(LocalFilesystemAdapter::class, $manager->disk('local'));
    }

    public function testDiskThrowsForUnknownAlias(): void
    {
        $manager = $this->makeManager();

        $this->expectException(RuntimeException::class);
        $manager->disk('nonexistent');
    }

    public function testConvenienceMethodsDelegateToDefaultDisk(): void
    {
        $container = new Container();
        $this->root = sys_get_temp_dir() . '/quiote-fs-manager-test-' . uniqid('', true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $container->set(LocalFilesystemAdapter::class, static fn() => $adapter);
        $manager = new FilesystemManager($container, new FilesystemConfig('local', $this->root));

        $manager->write('report.csv', 'data');

        $this->assertTrue($manager->exists('report.csv'));
        $this->assertSame('data', $manager->read('report.csv'));

        $manager->delete('report.csv');

        $this->assertFalse($manager->exists('report.csv'));
    }

    public function testListableDiskReturnsAListableDriver(): void
    {
        $manager = $this->makeManager();

        $this->assertInstanceOf(ListableFilesystemInterface::class, $manager->listableDisk());
    }

    public function testListContentsDelegatesToTheDefaultDisk(): void
    {
        $container = new Container();
        $this->root = sys_get_temp_dir() . '/quiote-fs-manager-test-' . uniqid('', true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $container->set(LocalFilesystemAdapter::class, static fn() => $adapter);
        $manager = new FilesystemManager($container, new FilesystemConfig('local', $this->root));

        $manager->write('one.csv', 'a');
        $manager->write('two.csv', 'b');

        $listed = $manager->listContents();
        sort($listed);

        $this->assertSame(['one.csv', 'two.csv'], $listed);
    }

    /**
     * A driver whose store has no list operation is rejected by name, at the point the disk is
     * resolved, rather than by an exception thrown from inside a listContents() call.
     */
    public function testListableDiskRejectsANonListableDriverByName(): void
    {
        FilesystemDriverRegistry::register('nolist', NonListableFakeAdapter::class);
        $container = new Container();
        $container->set(NonListableFakeAdapter::class, static fn() => new NonListableFakeAdapter());
        $manager = new FilesystemManager($container, new FilesystemConfig('nolist', sys_get_temp_dir()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/disk "nolist" cannot list its contents/');
        $this->expectExceptionMessageMatches('/NonListableFakeAdapter/');

        $manager->listableDisk();
    }

    public function testListContentsSurfacesTheSameRejection(): void
    {
        FilesystemDriverRegistry::register('nolist', NonListableFakeAdapter::class);
        $container = new Container();
        $container->set(NonListableFakeAdapter::class, static fn() => new NonListableFakeAdapter());
        $manager = new FilesystemManager($container, new FilesystemConfig('nolist', sys_get_temp_dir()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot list its contents/');

        $manager->listContents();
    }
}

/**
 * Stands in for the object-store drivers: everything but enumeration.
 */
final class NonListableFakeAdapter implements FilesystemAdapterInterface
{
    public function read(string $path): string
    {
        return '';
    }

    public function write(string $path, string $contents): void
    {
    }

    public function delete(string $path): void
    {
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function size(string $path): int
    {
        return 0;
    }

    public function lastModified(string $path): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
