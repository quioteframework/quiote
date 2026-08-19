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
use Quiote\Replay\Index\CassetteIndexInterface;
use Quiote\Replay\Index\CassetteIndexRegistry;
use Quiote\Replay\Store\Azure\Index\LogAnalyticsIndex;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\Storage\CassetteKeyScheme;
use Quiote\Replay\Store\Storage\Index\ExplicitKeyIndex;
use Quiote\Replay\Store\Storage\Index\PrefixScanIndex;
use Quiote\Replay\Store\Storage\ObjectStoreCassetteStore;
use Quiote\Storage\Azure\AzureBlobClient;
use Quiote\Storage\Azure\AzureBlobContainerClient;
use Quiote\Storage\Azure\AzureCredentialFactory;
use Quiote\Storage\Azure\AzureMonitorQueryClient;
use Quiote\Storage\Azure\AzureTokenProviderFactory;
use RuntimeException;

/**
 * Registers the `azure-blob` cassette store alias and its `CassetteStoreInterface` binding, and
 * contributes the three cassette-index strategies -- an explicit key, a Log Analytics lookup, and
 * a date-hinted prefix scan -- that let `quiote cassette:fetch`/`quiote replay --save` resolve a
 * bare id copied out of a log line back to a cassette, in that order: the explicit key always
 * wins when `--key` is given, Log Analytics resolves a bare id with no hint at all, and the prefix
 * scan is the fallback for a developer with blob read but no workspace access.
 *
 * Load order does not matter, and installing this package does not commit an application to Azure.
 * It contributes an alias, a factory, a config family and three index factories; `ReplayPlugin`'s
 * single `CassetteStoreInterface` binding then builds whichever store `replay.store` actually names.
 * Previously this plugin claimed that binding itself with a set-if-absent `service()` call, which
 * only worked when it loaded first -- and, having loaded first, then won regardless of
 * `replay.store`, so merely installing the package forced every cassette through a blob container
 * the application may never have named.
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
        // The environment segment of a cassette key, between the prefix and the date. Empty means
        // "this process's own core.environment", which is right for the deployment doing the
        // recording. A reader running somewhere else -- a developer's CLI, or the MCP assistant on
        // a laptop -- has a different core.environment than the deployment that wrote the key, and
        // core.environment is readonly once bootstrapped, so it cannot simply be overridden. This
        // is how such a reader names the environment whose cassettes it wants.
        $registrar->configDefault('replay.store.azure.env', '');
        $registrar->configDefault('replay.store.azure.lookback_hours', 48);
        // Empty by default: LogAnalyticsIndex declines (not an error) until a workspace is given.
        $registrar->configDefault('replay.index.log_analytics.workspace_id', '');
        $registrar->configDefault('replay.index.log_analytics.endpoint', 'https://api.loganalytics.io');
        $registrar->configDefault('replay.index.log_analytics.lookback_hours', 720);

        // The factory travels with the alias, so `ReplayPlugin`'s single binding can build this
        // store when -- and only when -- `replay.store` says `azure-blob`.
        CassetteStoreRegistry::register(
            'azure-blob',
            ObjectStoreCassetteStore::class,
            static fn(Container $container): ObjectStoreCassetteStore => self::makeStore($container),
        );
        $registrar->stateReset('quioteframework/replay-azure', static function (): void {
            CassetteStoreRegistry::reset();
            CassetteIndexRegistry::reset();
        });

        CassetteIndexRegistry::register(static fn(Container $container): CassetteIndexInterface => new ExplicitKeyIndex(self::makeObjectClient($container)));
        CassetteIndexRegistry::register(static fn(Container $container): CassetteIndexInterface => self::makeLogAnalyticsIndex($container));
        CassetteIndexRegistry::register(static fn(Container $container): CassetteIndexInterface => new PrefixScanIndex(self::makeObjectClient($container), self::makeKeyScheme()));
    }

    private static function makeStore(Container $container): ObjectStoreCassetteStore
    {
        return new ObjectStoreCassetteStore(
            self::makeObjectClient($container),
            self::makeKeyScheme(),
            storeAlias: 'azure-blob',
            containerLabel: Config::getString('replay.store.azure.container', 'quiote-cassettes'),
            lookbackHours: Config::getInt('replay.store.azure.lookback_hours', 48),
        );
    }

    /**
     * Built with `queryClient: null` (a permanent decline) when no workspace is configured --
     * config-driven, not a container/credential problem, so this never needs a bound HTTP client
     * just to find out the index is unused. It gets no object client either: one is only used to
     * fetch the object a pointer names, and building a blob client plus its credential for an index
     * that declines every call is work with no result. That matters for the common configuration --
     * blob storage with `--date`/`--key` hints and no workspace at all -- where this index is
     * present in the chain purely to be skipped.
     */
    private static function makeLogAnalyticsIndex(Container $container): LogAnalyticsIndex
    {
        $workspaceId = Config::getString('replay.index.log_analytics.workspace_id', '');
        if ($workspaceId === '') {
            return new LogAnalyticsIndex(null);
        }

        $endpoint = Config::getString('replay.index.log_analytics.endpoint', 'https://api.loganalytics.io');
        $httpClient = self::requireHttpClient($container);
        $tokenProvider = AzureTokenProviderFactory::fromConfig(
            ['auth' => Config::getString('replay.store.azure.auth', 'shared_key')],
            $httpClient,
            rtrim($endpoint, '/') . '/',
            logger: Log::create(self::class),
        );

        $queryClient = new AzureMonitorQueryClient($httpClient, $tokenProvider, $workspaceId, $endpoint);

        return new LogAnalyticsIndex($queryClient, self::makeObjectClient($container), Config::getInt('replay.index.log_analytics.lookback_hours', 720));
    }

    private static function makeObjectClient(Container $container): AzureBlobContainerClient
    {
        $httpClient = self::requireHttpClient($container);
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

        return new AzureBlobContainerClient($blobClient, $containerName);
    }

    /**
     * The key scheme, whose environment segment is `replay.store.azure.env` when set and this
     * process's own `core.environment` otherwise -- see that config default for why a reader needs
     * to be able to say which environment's keys it is after.
     */
    private static function makeKeyScheme(): CassetteKeyScheme
    {
        $env = Config::getString('replay.store.azure.env', '');

        return new CassetteKeyScheme(
            Config::getString('replay.store.azure.prefix', 'quiote-cassettes'),
            $env !== '' ? $env : Config::getString('core.environment', 'production'),
        );
    }

    private static function requireHttpClient(Container $container): ClientInterface
    {
        $httpClient = $container->tryGet(ClientInterface::class);
        if (!$httpClient instanceof ClientInterface) {
            throw new RuntimeException(sprintf(
                'quioteframework/replay-azure needs a %s bound in the container -- none found. '
                . 'Bind your PSR-18 client, the same way quioteframework/session-azure expects.',
                ClientInterface::class,
            ));
        }

        return $httpClient;
    }
}
