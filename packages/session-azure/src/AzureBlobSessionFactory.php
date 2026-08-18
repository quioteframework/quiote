<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Context;
use Quiote\Logging\Log;
use Quiote\Session\SessionFactoryInterface;
use Quiote\Session\SessionPersistenceInterface;
use RuntimeException;

/**
 * `session` slot factory for {@see AzureBlobSessionPersistence}.
 *
 * ```yaml
 * session:
 *   class: Quiote\Storage\Azure\AzureBlobSessionFactory
 *   params:
 *     account_name: '%env(AZURE_STORAGE_ACCOUNT)%'
 *     account_key: '%env(AZURE_STORAGE_KEY)%'
 *     container: quiote-sessions
 * ```
 *
 * `auth` selects how requests are authorized: `shared_key` (default, needs
 * `account_key`), `workload_identity` (AKS, reads the webhook's own
 * environment variables), `cli` (a developer's `az login` session) or
 * `chain` (workload identity, falling back to the CLI). Only `shared_key`
 * ever reads a storage account key.
 *
 * For small key/value-shaped session payloads
 * {@see AzureTableSessionFactory} is cheaper. Bring your own PSR-18 client,
 * bound in the container.
 *
 * @since      3.0.0
 */
final class AzureBlobSessionFactory implements SessionFactoryInterface
{
    /**
     * Builds blob-backed session persistence from the slot's parameters.
     *
     * Reads `account_name`, `auth`, `account_key`, an optional `endpoint`
     * (empty means the public `*.blob.core.windows.net` origin) and
     * `container`, which defaults to `quiote-sessions`. The PSR-18 client
     * comes from the container, not from the parameters.
     *
     * @param array<string, mixed> $parameters
     * @throws \RuntimeException If no PSR-18 client is bound in the container.
     */
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        $endpoint = AzureSessionParameters::str($parameters, 'endpoint');
        $httpClient = AzureSessionParameters::httpClient($context, 'Blob');

        return new AzureBlobSessionPersistence(
            new AzureBlobClient(
                $httpClient,
                AzureSessionParameters::str($parameters, 'account_name'),
                AzureCredentialFactory::fromConfig([
                    'auth' => AzureSessionParameters::str($parameters, 'auth', 'shared_key'),
                    'account_key' => AzureSessionParameters::str($parameters, 'account_key'),
                ], $httpClient, logger: Log::for(self::class)),
                $endpoint !== '' ? $endpoint : null,
                new Psr17Factory(),
            ),
            AzureSessionParameters::str($parameters, 'container', 'quiote-sessions'),
        );
    }
}
