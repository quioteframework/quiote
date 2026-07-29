<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Context;
use Quiote\Session\SessionFactoryInterface;
use Quiote\Session\SessionPersistenceInterface;

/**
 * `session` slot factory for {@see AzureTableSessionPersistence}.
 *
 * ```yaml
 * session:
 *   class: Quiote\Storage\Azure\AzureTableSessionFactory
 *   params:
 *     account_name: '%env(AZURE_STORAGE_ACCOUNT)%'
 *     account_key: '%env(AZURE_STORAGE_KEY)%'
 *     table: sessions
 * ```
 *
 * Cheaper than {@see AzureBlobSessionFactory} for small key/value-shaped
 * payloads. Bring your own PSR-18 client, bound in the container.
 *
 * @since      3.0.0
 */
final class AzureTableSessionFactory implements SessionFactoryInterface
{
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        $endpoint = AzureSessionParameters::str($parameters, 'endpoint');

        return new AzureTableSessionPersistence(
            new AzureTableClient(
                AzureSessionParameters::httpClient($context, 'Table'),
                AzureSessionParameters::str($parameters, 'account_name'),
                AzureSessionParameters::str($parameters, 'account_key'),
                $endpoint !== '' ? $endpoint : null,
                new Psr17Factory(),
            ),
            AzureSessionParameters::str($parameters, 'table', 'sessions'),
        );
    }
}
