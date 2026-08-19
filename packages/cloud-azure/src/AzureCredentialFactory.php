<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
    /**
     * @param array<string, string> $config Keys: `auth` (default `shared_key`), `account_key`.
     * @param LoggerInterface $logger PSR-3, so a Quiote application can pass its own
     *        `Quiote\Logging\Log::for(...)` (it already implements the interface) without this
     *        package needing the framework as a dependency. Defaults to discarding everything.
     */
    public static function fromConfig(array $config, ClientInterface $httpClient, Psr17Factory $psr17 = new Psr17Factory(), LoggerInterface $logger = new NullLogger()): AzureCredential
    {
        $auth = $config['auth'] ?? 'shared_key';
        if ($auth === 'shared_key') {
            return new SharedKeyCredential($config['account_key'] ?? '');
        }

        return new BearerCredential(AzureTokenProviderFactory::fromConfig($config, $httpClient, AzureTokenProvider::STORAGE_RESOURCE, $psr17, $logger));
    }
}
