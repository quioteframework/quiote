<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Filesystem\S3\S3FilesystemAdapter;
use Quiote\Filesystem\S3\S3FilesystemPlugin;
use Quiote\Plugin\PluginManager;

final class S3FilesystemPluginTest extends TestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        FilesystemDriverRegistry::reset();
        Config::remove('filesystem.disks.s3.region');
        Config::remove('filesystem.disks.s3.bucket');
        Config::remove('filesystem.disks.s3.access_key_id');
        Config::remove('filesystem.disks.s3.secret_access_key');
        Config::remove('filesystem.disks.s3.endpoint');
        Config::remove('filesystem.disks.s3.key_prefix');
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new S3FilesystemPlugin());
        PluginManager::bootFromConfig();

        $this->assertSame('us-east-1', Config::getString('filesystem.disks.s3.region'));
        $this->assertSame('', Config::getString('filesystem.disks.s3.bucket'));
    }

    public function testRegistersTheS3DriverAlias(): void
    {
        PluginManager::add(new S3FilesystemPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(FilesystemDriverRegistry::has('s3'));
        $this->assertSame(S3FilesystemAdapter::class, FilesystemDriverRegistry::resolve('s3'));
    }

    public function testWiresTheAdapterServiceGivenAnHttpClient(): void
    {
        PluginManager::add(new S3FilesystemPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        $container->set(ClientInterface::class, static fn() => new class implements ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('not used in this test');
            }
        });
        PluginManager::configureContainer($container);

        $this->assertInstanceOf(S3FilesystemAdapter::class, $container->get(S3FilesystemAdapter::class));
    }

    public function testAdapterServiceThrowsWithoutAnHttpClientBound(): void
    {
        PluginManager::add(new S3FilesystemPlugin());
        PluginManager::bootFromConfig();

        $container = new Container();
        PluginManager::configureContainer($container);

        $this->expectException(RuntimeException::class);
        $container->get(S3FilesystemAdapter::class);
    }
}
