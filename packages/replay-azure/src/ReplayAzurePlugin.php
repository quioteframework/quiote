<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Config\Config;
use Quiote\DI\Container;
use Quiote\Logging\Log;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;
use Quiote\Replay\Store\Storage\ObjectStoreCassetteStore;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureBlobContainerClient;
use Quiote\Storage\Azure\AzureCredentialFactory;
use RuntimeException;

/**
 * Registers the `azure-blob` cassette store alias and its
 * `CassetteStoreInterface` binding, per `docs/RECORD_REPLAY_PLAN.md` §12's
 * own concrete target deployment (AKS + Azure Blob + Log Analytics).
 *
 * **Must be loaded before `Quiote\Replay\ReplayPlugin`** -- same reason as
 * `quioteframework/replay-pdo`'s own plugin: `PluginRegistrar::service()` is
 * set-if-absent, and `ReplayPlugin`'s own factory only knows how to build
 * the file store.
 */
#[PluginAttribute(name: 'quioteframework/replay-azure')]
final class ReplayAzurePlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('replay.store.azure.container', 'quiote-cassettes');
        $registrar->configDefault('replay.store.azure.account', '');
        $registrar->configDefault('replay.store.azure.auth', 'shared_key');
        $registrar->configDefault('replay.store.azure.account_key', '');
        $registrar->configDefault('replay.store.azure.endpoint', '');
        $registrar->configDefault('replay.store.azure.prefix', 'quiote-cassettes');
        $registrar->configDefault('replay.store.azure.lookback_hours', 48);

        CassetteStoreRegistry::register('azure-blob', ObjectStoreCassetteStore::class);
        $registrar->stateReset('quioteframework/replay-azure', static fn() => CassetteStoreRegistry::reset());

        $registrar->service(
            CassetteStoreInterface::class,
            static fn(Container $container): CassetteStoreInterface => self::makeStore($container),
            Container::SCOPE_SINGLETON,
        );
    }

    private static function makeStore(Container $container): ObjectStoreCassetteStore
    {
        $httpClient = $container->tryGet(ClientInterface::class);
        if (!$httpClient instanceof ClientInterface) {
            throw new RuntimeException(sprintf(
                'quioteframework/replay-azure needs a %s bound in the container -- none found. '
                . 'Bind your PSR-18 client, the same way quioteframework/session-azure expects.',
                ClientInterface::class,
            ));
        }

        $accountName = Config::getString('replay.store.azure.account', '');
        $containerName = Config::getString('replay.store.azure.container', 'quiote-cassettes');
        $endpoint = Config::getNullableString('replay.store.azure.endpoint');

        $credential = AzureCredentialFactory::fromConfig(
            [
                'auth' => Config::getString('replay.store.azure.auth', 'shared_key'),
                'account_key' => Config::getString('replay.store.azure.account_key', ''),
            ],
            $httpClient,
            logger: Log::create(self::class),
        );

        $blobClient = new AzureBlobClient(
            $httpClient,
            $accountName,
            $credential,
            $endpoint !== null && $endpoint !== '' ? $endpoint : null,
            new Psr17Factory(),
        );

        $keyScheme = new CassetteKeyScheme(
            Config::getString('replay.store.azure.prefix', 'quiote-cassettes'),
            Config::getString('core.environment', 'production'),
        );

        return new ObjectStoreCassetteStore(
            new AzureBlobContainerClient($blobClient, $containerName),
            $keyScheme,
            storeAlias: 'azure-blob',
            containerLabel: $containerName,
            lookbackHours: Config::getInt('replay.store.azure.lookback_hours', 48),
        );
    }
}
