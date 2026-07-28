<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Filesystem\Gcs\GcsFilesystemAdapter;
use Quiote\Filesystem\Gcs\GcsFilesystemPlugin;
use Quiote\Plugin\PluginManager;

final class GcsFilesystemPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        FilesystemDriverRegistry::reset();
        Config::remove('filesystem.disks.gcs.bucket');
        Config::remove('filesystem.disks.gcs.access_key');
        Config::remove('filesystem.disks.gcs.secret_key');
        Config::remove('filesystem.disks.gcs.endpoint');
        Config::remove('filesystem.disks.gcs.key_prefix');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new GcsFilesystemPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('https://storage.googleapis.com', Config::getString('filesystem.disks.gcs.endpoint'));
        $this->assertSame('', Config::getString('filesystem.disks.gcs.bucket'));
    }

    public function testRegistersTheGcsDriverAlias(): void
    {
        PluginManager::add(new GcsFilesystemPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(FilesystemDriverRegistry::has('gcs'));
        $this->assertSame(GcsFilesystemAdapter::class, FilesystemDriverRegistry::resolve('gcs'));
    }

    public function testWiresTheAdapterServiceGivenAnHttpClient(): void
    {
        PluginManager::add(new GcsFilesystemPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, static fn() => new class implements ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('not used in this test');
            }
        });
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(GcsFilesystemAdapter::class, $container->get(GcsFilesystemAdapter::class));
    }

    public function testAdapterServiceThrowsWithoutAnHttpClientBound(): void
    {
        PluginManager::add(new GcsFilesystemPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->expectException(RuntimeException::class);
        $container->get(GcsFilesystemAdapter::class);
    }
}
