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
use Quiote\Storage\Azure\AzureCredentialFactory;
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
    /**
     * Publishes the `filesystem.disks.azure.*` defaults, registers the `azure` driver alias and
     * binds {@see AzureFilesystemAdapter} as a singleton.
     *
     * The adapter's factory reads account name, auth strategy, container, optional endpoint and
     * key prefix from config at resolution time and pulls the PSR-18 client out of the container
     * then, so registering this plugin without an HTTP client bound only fails once the disk is
     * used.
     *
     * `filesystem.disks.azure.auth` selects how requests are authorized:
     * `shared_key` (default, needs `account_key`), `workload_identity` (AKS,
     * reads the webhook's own environment variables), `cli` (a developer's
     * `az login` session) or `chain` (workload identity, falling back to the
     * CLI). Only `shared_key` ever reads a storage account key.
     */
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('filesystem.disks.azure.account_name', '');
        $registrar->configDefault('filesystem.disks.azure.auth', 'shared_key');
        $registrar->configDefault('filesystem.disks.azure.account_key', '');
        $registrar->configDefault('filesystem.disks.azure.container', '');
        $registrar->configDefault('filesystem.disks.azure.endpoint', '');
        $registrar->configDefault('filesystem.disks.azure.key_prefix', '');

        FilesystemDriverRegistry::register('azure', AzureFilesystemAdapter::class);

        $registrar->service(
            AzureFilesystemAdapter::class,
            static function (Container $container): AzureFilesystemAdapter {
                $httpClient = self::resolveHttpClient($container);

                return new AzureFilesystemAdapter(
                    new AzureBlobClient(
                        $httpClient,
                        Config::getString('filesystem.disks.azure.account_name', ''),
                        AzureCredentialFactory::fromConfig([
                            'auth' => Config::getString('filesystem.disks.azure.auth', 'shared_key'),
                            'account_key' => Config::getString('filesystem.disks.azure.account_key', ''),
                        ], $httpClient),
                        Config::getNullableString('filesystem.disks.azure.endpoint', null) ?: null,
                        new Psr17Factory(),
                    ),
                    Config::getString('filesystem.disks.azure.container', ''),
                    Config::getString('filesystem.disks.azure.key_prefix', ''),
                );
            },
            Container::SCOPE_SINGLETON,
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
                'The "azure" filesystem disk requires a %s bound in the container, none found. '
                . 'Bind your PSR-18 client before enabling this disk.',
                ClientInterface::class,
            ));
        }
        return $client;
    }
}
