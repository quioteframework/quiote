<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemConfig;
use Quiote\Filesystem\FilesystemManager;
use Quiote\Filesystem\FilesystemPlugin;
use Quiote\Filesystem\LocalFilesystemAdapter;
use Quiote\Plugin\PluginManager;

/**
 * FilesystemPlugin::register() -- config defaults + the DI services app code
 * (via FilesystemManager) depends on. Mirrors QueuePluginTest's shape.
 */
final class FilesystemPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        Config::remove('filesystem.default_disk');
        Config::remove('filesystem.disks.local.root');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new FilesystemPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('local', Config::getString('filesystem.default_disk'));
        $this->assertSame('storage/app', Config::getString('filesystem.disks.local.root'));
    }

    public function testWiresFilesystemServicesIntoTheContainer(): void
    {
        // Override the local root to a throwaway temp directory so this test
        // doesn't create a real "storage/app" directory under the repo root.
        $root = sys_get_temp_dir() . '/quiote-fs-plugin-test-' . uniqid('', true);
        Config::set('filesystem.disks.local.root', $root, overwrite: true);

        PluginManager::add(new FilesystemPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        try {
            $this->assertInstanceOf(FilesystemConfig::class, $container->get(FilesystemConfig::class));
            $this->assertInstanceOf(LocalFilesystemAdapter::class, $container->get(LocalFilesystemAdapter::class));
            $this->assertInstanceOf(FilesystemManager::class, $container->get(FilesystemManager::class));
        } finally {
            $this->removeDirectory($root);
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
}
