<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Logging\Log;

/**
 * Builds the {@see AzureCredential} a config `auth` value asks for, so
 * `quioteframework/session-azure` and `quioteframework/filesystem-azure` share one place that
 * knows how to turn `shared_key` / `workload_identity` / `cli` / `chain` into an instance rather
 * than each re-implementing the same branch.
 *
 * `workload_identity` and `cli` read their own configuration from the environment (the AKS
 * webhook's variables, respectively an existing `az login` session): nothing beyond `auth`
 * itself is required from `$config` for them. Only `shared_key` needs `account_key`.
 */
final class AzureCredentialFactory
{
    /** @param array<string, string> $config Keys: `auth` (default `shared_key`), `account_key`. */
    public static function fromConfig(array $config, ClientInterface $httpClient, Psr17Factory $psr17 = new Psr17Factory()): AzureCredential
    {
        $auth = $config['auth'] ?? 'shared_key';

        return match ($auth) {
            'shared_key' => new SharedKeyCredential($config['account_key'] ?? ''),
            'workload_identity' => new BearerCredential(WorkloadIdentityTokenProvider::fromEnvironment($httpClient, $psr17)),
            'cli' => new BearerCredential(new AzureCliTokenProvider()),
            'chain' => new BearerCredential(new ChainedTokenProvider(self::chainProviders($httpClient, $psr17))),
            default => throw new AzureStorageException("Unknown Azure auth strategy \"{$auth}\", expected shared_key, workload_identity, cli or chain."),
        };
    }

    /**
     * Workload identity's environment variables are only present inside an annotated AKS pod, so
     * outside one `fromEnvironment()` throws at construction time rather than at
     * {@see AzureTokenProvider::getToken()}, too early for {@see ChainedTokenProvider} to fall
     * through to the CLI on its own. Skipping the provider here, before it is ever added to the
     * chain, is what makes `chain` usable both in-cluster and on a developer's machine.
     *
     * @return non-empty-list<AzureTokenProvider>
     */
    private static function chainProviders(ClientInterface $httpClient, Psr17Factory $psr17): array
    {
        $providers = [];
        try {
            $providers[] = WorkloadIdentityTokenProvider::fromEnvironment($httpClient, $psr17);
        } catch (AzureStorageException $e) {
            Log::create(self::class)->debug("Workload identity is not available, the chain will rely on the CLI provider: {$e->getMessage()}");
        }
        $providers[] = new AzureCliTokenProvider();

        return $providers;
    }
}
