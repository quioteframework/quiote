<?php

declare(strict_types=1);

namespace Quiote\Filesystem\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Filesystem\FilesystemDriverRegistry;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Storage\Azure\AzureBlobClient;
use RuntimeException;

/**
 * Registers the `azure` filesystem driver alias and publishes
 * `filesystem.disks.azure.*` config defaults. Requires a PSR-18
 * {@see ClientInterface} to already be bound in the container (bring your
 * own HTTP client, same as `quioteframework/session-azure`).
 */
#[PluginAttribute(name: 'quiote/filesystem-azure')]
final class AzureFilesystemPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('filesystem.disks.azure.account_name', '');
        $registrar->configDefault('filesystem.disks.azure.account_key', '');
        $registrar->configDefault('filesystem.disks.azure.container', '');
        $registrar->configDefault('filesystem.disks.azure.endpoint', '');
        $registrar->configDefault('filesystem.disks.azure.key_prefix', '');

        FilesystemDriverRegistry::register('azure', AzureFilesystemAdapter::class);

        $registrar->service(
            AzureFilesystemAdapter::class,
            static fn(Container $container) => new AzureFilesystemAdapter(
                new AzureBlobClient(
                    self::resolveHttpClient($container),
                    Config::getString('filesystem.disks.azure.account_name', ''),
                    Config::getString('filesystem.disks.azure.account_key', ''),
                    Config::getNullableString('filesystem.disks.azure.endpoint', null) ?: null,
                    new Psr17Factory(),
                ),
                Config::getString('filesystem.disks.azure.container', ''),
                Config::getString('filesystem.disks.azure.key_prefix', ''),
            ),
        );
    }

    private static function resolveHttpClient(Container $container): ClientInterface
    {
        // tryGet(), not get(): the container throws for an unregistered,
        // non-autowireable service, so the instanceof check below could never
        // actually run and the caller saw an autowiring error instead of the
        // message that tells them what to bind.
        $client = $container->tryGet(ClientInterface::class);
        if (!$client instanceof ClientInterface) {
            throw new RuntimeException(sprintf(
                'The "azure" filesystem disk requires a %s bound in the container — none found. '
                . 'Bind your PSR-18 client before enabling this disk.',
                ClientInterface::class,
            ));
        }
        return $client;
    }
}
