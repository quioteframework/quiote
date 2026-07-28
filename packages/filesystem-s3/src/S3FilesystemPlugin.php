<?php

declare(strict_types=1);

namespace Quiote\Filesystem\S3;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Storage\S3\S3Client;
use RuntimeException;

/**
 * Registers the `s3` filesystem driver alias and publishes
 * `filesystem.disks.s3.*` config defaults. Requires a PSR-18
 * {@see ClientInterface} to already be bound in the container (bring your
 * own HTTP client, same as `quioteframework/session-s3`).
 */
#[PluginAttribute(name: 'quiote/filesystem-s3')]
final class S3FilesystemPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('filesystem.disks.s3.region', 'us-east-1');
        $registrar->configDefault('filesystem.disks.s3.bucket', '');
        $registrar->configDefault('filesystem.disks.s3.access_key_id', '');
        $registrar->configDefault('filesystem.disks.s3.secret_access_key', '');
        $registrar->configDefault('filesystem.disks.s3.endpoint', '');
        $registrar->configDefault('filesystem.disks.s3.key_prefix', '');

        FilesystemDriverRegistry::register('s3', S3FilesystemAdapter::class);

        $registrar->service(
            S3FilesystemAdapter::class,
            static fn(Container $container) => new S3FilesystemAdapter(
                new S3Client(
                    self::resolveHttpClient($container),
                    Config::getString('filesystem.disks.s3.region', 'us-east-1'),
                    Config::getString('filesystem.disks.s3.access_key_id', ''),
                    Config::getString('filesystem.disks.s3.secret_access_key', ''),
                    Config::getString('filesystem.disks.s3.bucket', ''),
                    Config::getNullableString('filesystem.disks.s3.endpoint', null) ?: null,
                    new Psr17Factory(),
                ),
                Config::getString('filesystem.disks.s3.key_prefix', ''),
            ),
        );
    }

    private static function resolveHttpClient(Container $container): ClientInterface
    {
        $client = $container->get(ClientInterface::class);
        if (!$client instanceof ClientInterface) {
            throw new RuntimeException(sprintf(
                'The "s3" filesystem disk requires a %s bound in the container — none found.',
                ClientInterface::class,
            ));
        }
        return $client;
    }
}
