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
     * @param array<string, string> $config Keys: `auth` (`workload_identity` | `managed_identity`
     *        | `cli` | `chain`), `client_id` (only meaningful for `managed_identity`, to select a
     *        user-assigned identity when the node/pod carries more than one).
     * @param string $resource The `az account get-access-token --resource` form: an https URL
     *        ending in `/`. The client-credentials `scope` used for `workload_identity`/`chain`
     *        is derived from this by appending `.default`, per {@see AzureTokenProvider}'s own
     *        docblock. `managed_identity` uses the resource form directly, IMDS has no separate
     *        scope parameter.
     * @param LoggerInterface $logger PSR-3, so a Quiote application can pass its own
     *        `Quiote\Logging\Log::for(...)` without this package needing the framework as a
     *        dependency. Defaults to discarding everything.
     * @param ?\Closure(): bool $isManagedIdentityReachable Only consulted for `chain`, to decide
     *        whether {@see ManagedIdentityTokenProvider} belongs in it at all -- overridable so a
     *        test can substitute a deterministic answer instead of a real, environment-dependent
     *        probe of IMDS's link-local address. Defaults to
     *        {@see ManagedIdentityTokenProvider::isReachable()}.
     */
    public static function fromConfig(
        array $config,
        ClientInterface $httpClient,
        string $resource = AzureTokenProvider::STORAGE_RESOURCE,
        Psr17Factory $psr17 = new Psr17Factory(),
        LoggerInterface $logger = new NullLogger(),
        ?\Closure $isManagedIdentityReachable = null,
    ): AzureTokenProvider {
        $auth = $config['auth'] ?? 'shared_key';

        return match ($auth) {
            'workload_identity' => WorkloadIdentityTokenProvider::fromEnvironment($httpClient, $psr17, $resource . '.default'),
            'managed_identity' => new ManagedIdentityTokenProvider($httpClient, $resource, $config['client_id'] ?? null, psr17: $psr17),
            'cli' => new AzureCliTokenProvider(resource: $resource),
            'chain' => new ChainedTokenProvider(
                self::chainProviders($httpClient, $psr17, $logger, $resource, $config['client_id'] ?? null, $isManagedIdentityReachable ?? static fn (): bool => ManagedIdentityTokenProvider::isReachable()),
                $logger,
            ),
            default => throw new AzureStorageException(
                "Unknown or unsupported Azure token-provider auth strategy \"{$auth}\", expected "
                . 'workload_identity, managed_identity, cli or chain (shared_key is a storage '
                . 'request-signing scheme, not a bearer token, and cannot authenticate an '
                . 'AAD-only API).',
            ),
        };
    }

    /**
     * Workload identity's environment variables are only present inside an annotated AKS pod, so
     * `fromEnvironment()` throws at construction time rather than at
     * {@see AzureTokenProvider::getToken()}, too early for {@see ChainedTokenProvider} to fall
     * through to the next candidate on its own. Skipping the provider here, before it is ever
     * added to the chain, is what makes `chain` usable both in-cluster and on a developer's
     * machine.
     *
     * Managed identity has no environment precondition the way workload identity does -- IMDS is
     * either reachable or it isn't, and the official SDKs only find that out once a real token
     * request hits it. Doing the same here would mean trusting the chain's request to whatever
     * PSR-18 client the caller supplied, with no guarantee it enforces a timeout of its own: on a
     * developer machine or CI runner, with no IMDS to answer, that risks the same request hanging
     * for however long the client is willing to wait. So this eagerly probes IMDS's link-local
     * address with a short, hardcoded connect timeout ({@see ManagedIdentityTokenProvider::isReachable()})
     * before adding the provider to the chain at all -- matching the official SDKs' ordering
     * (`DefaultAzureCredential` tries managed identity between workload identity and a developer's
     * `az login` session) without inheriting their assumption that IMDS fails fast.
     *
     * @param \Closure(): bool $isManagedIdentityReachable
     * @return non-empty-list<AzureTokenProvider>
     */
    private static function chainProviders(ClientInterface $httpClient, Psr17Factory $psr17, LoggerInterface $logger, string $resource, ?string $clientId, \Closure $isManagedIdentityReachable): array
    {
        $providers = [];
        try {
            $providers[] = WorkloadIdentityTokenProvider::fromEnvironment($httpClient, $psr17, $resource . '.default');
        } catch (AzureStorageException $e) {
            $logger->debug("Workload identity is not available, the chain will fall through to managed identity: {$e->getMessage()}");
        }
        if ($isManagedIdentityReachable()) {
            $providers[] = new ManagedIdentityTokenProvider($httpClient, $resource, $clientId, psr17: $psr17);
        } else {
            $logger->debug('IMDS is not reachable, the chain will fall through to the Azure CLI.');
        }
        $providers[] = new AzureCliTokenProvider(resource: $resource);

        return $providers;
    }
}
