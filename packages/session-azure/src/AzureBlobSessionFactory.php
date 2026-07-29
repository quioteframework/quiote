<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Context;
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
 * For small key/value-shaped session payloads
 * {@see AzureTableSessionFactory} is cheaper. Bring your own PSR-18 client,
 * bound in the container.
 *
 * @since      3.0.0
 */
final class AzureBlobSessionFactory implements SessionFactoryInterface
{
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        $endpoint = AzureSessionParameters::str($parameters, 'endpoint');

        return new AzureBlobSessionPersistence(
            new AzureBlobClient(
                AzureSessionParameters::httpClient($context, 'Blob'),
                AzureSessionParameters::str($parameters, 'account_name'),
                AzureSessionParameters::str($parameters, 'account_key'),
                $endpoint !== '' ? $endpoint : null,
                new Psr17Factory(),
            ),
            AzureSessionParameters::str($parameters, 'container', 'quiote-sessions'),
        );
    }
}
