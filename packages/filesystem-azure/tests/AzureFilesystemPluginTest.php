<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Filesystem\Azure\AzureFilesystemAdapter;
use Quiote\Filesystem\Azure\AzureFilesystemPlugin;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Plugin\PluginManager;

final class AzureFilesystemPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        FilesystemDriverRegistry::reset();
        Config::remove('filesystem.disks.azure.account_name');
        Config::remove('filesystem.disks.azure.account_key');
        Config::remove('filesystem.disks.azure.container');
        Config::remove('filesystem.disks.azure.endpoint');
        Config::remove('filesystem.disks.azure.key_prefix');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new AzureFilesystemPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('', Config::getString('filesystem.disks.azure.account_name'));
        $this->assertSame('', Config::getString('filesystem.disks.azure.container'));
    }

    public function testRegistersTheAzureDriverAlias(): void
    {
        PluginManager::add(new AzureFilesystemPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(FilesystemDriverRegistry::has('azure'));
        $this->assertSame(AzureFilesystemAdapter::class, FilesystemDriverRegistry::resolve('azure'));
    }

    public function testWiresTheAdapterServiceGivenAnHttpClient(): void
    {
        PluginManager::add(new AzureFilesystemPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, static fn() => new class implements ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('not used in this test');
            }
        });
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(AzureFilesystemAdapter::class, $container->get(AzureFilesystemAdapter::class));
    }

    public function testAdapterServiceThrowsWithoutAnHttpClientBound(): void
    {
        PluginManager::add(new AzureFilesystemPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->expectException(RuntimeException::class);
        $container->get(AzureFilesystemAdapter::class);
    }
}
