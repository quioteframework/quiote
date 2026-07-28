<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Gcs;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Storage\Gcs\GcsClient;
use RuntimeException;

/**
 * Registers the `gcs` filesystem driver alias and publishes
 * `filesystem.disks.gcs.*` config defaults. Requires a PSR-18
 * {@see ClientInterface} to already be bound in the container (bring your
 * own HTTP client, same as `quioteframework/session-gcs`).
 */
#[PluginAttribute(name: 'quiote/filesystem-gcs')]
final class GcsFilesystemPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('filesystem.disks.gcs.bucket', '');
        $registrar->configDefault('filesystem.disks.gcs.access_key', '');
        $registrar->configDefault('filesystem.disks.gcs.secret_key', '');
        $registrar->configDefault('filesystem.disks.gcs.endpoint', 'https://storage.googleapis.com');
        $registrar->configDefault('filesystem.disks.gcs.key_prefix', '');

        FilesystemDriverRegistry::register('gcs', GcsFilesystemAdapter::class);

        $registrar->service(
            GcsFilesystemAdapter::class,
            static fn(Container $container) => new GcsFilesystemAdapter(
                new GcsClient(
                    self::resolveHttpClient($container),
                    Config::getString('filesystem.disks.gcs.access_key', ''),
                    Config::getString('filesystem.disks.gcs.secret_key', ''),
                    Config::getString('filesystem.disks.gcs.bucket', ''),
                    Config::getString('filesystem.disks.gcs.endpoint', 'https://storage.googleapis.com'),
                    new Psr17Factory(),
                ),
                Config::getString('filesystem.disks.gcs.key_prefix', ''),
            ),
        );
    }

    private static function resolveHttpClient(Container $container): ClientInterface
    {
        $client = $container->get(ClientInterface::class);
        if (!$client instanceof ClientInterface) {
            throw new RuntimeException(sprintf(
                'The "gcs" filesystem disk requires a %s bound in the container — none found.',
                ClientInterface::class,
            ));
        }
        return $client;
    }
}
