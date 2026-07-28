<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemConfig;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Filesystem\FilesystemManager;
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
}
