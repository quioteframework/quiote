<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds a bare {@see AzureTokenProvider} for whichever `auth` a config value asks for, scoped to
 * an arbitrary AAD resource -- not only storage. {@see AzureCredentialFactory} is the
 * storage-flavoured caller (it wraps the result in a {@see BearerCredential}); a caller
 * authenticating against a different AAD-protected API (Log Analytics, another Azure REST
 * surface) resolves its own {@see AzureTokenProvider} straight from here instead.
 *
 * `shared_key` is deliberately not one of the strategies this factory knows: it is a
 * request-signing scheme tied to a storage account key, not a bearer token, and has no meaning
 * for an API that only accepts Azure AD tokens.
 */
final class AzureTokenProviderFactory
{
    /**
     * @param array<string, string> $config Keys: `auth` (`workload_identity` | `cli` | `chain`).
     * @param string $resource The `az account get-access-token --resource` form: an https URL
     *        ending in `/`. The client-credentials `scope` used for `workload_identity`/`chain`
     *        is derived from this by appending `.default`, per {@see AzureTokenProvider}'s own
     *        docblock.
     * @param LoggerInterface $logger PSR-3, so a Quiote application can pass its own
     *        `Quiote\Logging\Log::for(...)` without this package needing the framework as a
     *        dependency. Defaults to discarding everything.
     */
    public static function fromConfig(
        array $config,
        ClientInterface $httpClient,
        string $resource = AzureTokenProvider::STORAGE_RESOURCE,
        Psr17Factory $psr17 = new Psr17Factory(),
        LoggerInterface $logger = new NullLogger(),
    ): AzureTokenProvider {
        $auth = $config['auth'] ?? 'shared_key';

        return match ($auth) {
            'workload_identity' => WorkloadIdentityTokenProvider::fromEnvironment($httpClient, $psr17, $resource . '.default'),
            'cli' => new AzureCliTokenProvider(resource: $resource),
            'chain' => new ChainedTokenProvider(self::chainProviders($httpClient, $psr17, $logger, $resource), $logger),
            default => throw new AzureStorageException(
                "Unknown or unsupported Azure token-provider auth strategy \"{$auth}\", expected "
                . 'workload_identity, cli or chain (shared_key is a storage request-signing '
                . 'scheme, not a bearer token, and cannot authenticate an AAD-only API).',
            ),
        };
    }

    /**
     * Workload identity's environment variables are only present inside an annotated AKS pod, so
     * `fromEnvironment()` throws at construction time rather than at
     * {@see AzureTokenProvider::getToken()}, too early for {@see ChainedTokenProvider} to fall
     * through to the CLI on its own. Skipping the provider here, before it is ever added to the
     * chain, is what makes `chain` usable both in-cluster and on a developer's machine.
     *
     * @return non-empty-list<AzureTokenProvider>
     */
    private static function chainProviders(ClientInterface $httpClient, Psr17Factory $psr17, LoggerInterface $logger, string $resource): array
    {
        $providers = [];
        try {
            $providers[] = WorkloadIdentityTokenProvider::fromEnvironment($httpClient, $psr17, $resource . '.default');
        } catch (AzureStorageException $e) {
            $logger->debug("Workload identity is not available, the chain will rely on the CLI provider: {$e->getMessage()}");
        }
        $providers[] = new AzureCliTokenProvider(resource: $resource);

        return $providers;
    }
}
