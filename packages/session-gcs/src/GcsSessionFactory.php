<?php

declare(strict_types=1);

namespace Quiote\Storage\Gcs;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Context;
use Quiote\Session\SessionFactoryInterface;
use Quiote\Session\SessionPersistenceInterface;
use RuntimeException;

/**
 * `session` slot factory for {@see GcsSessionPersistence}.
 *
 * ```yaml
 * session:
 *   class: Quiote\Storage\Gcs\GcsSessionFactory
 *   params:
 *     bucket: my-app-sessions
 *     access_key: '%env(GCS_HMAC_ACCESS_KEY)%'
 *     secret_key: '%env(GCS_HMAC_SECRET)%'
 *     object_prefix: 'sessions/'
 * ```
 *
 * Uses GCS's S3-compatible HMAC interoperability API, so the credentials are
 * an HMAC key pair rather than a service-account JSON file. Bring your own
 * PSR-18 client, bound in the container.
 *
 * @since      3.0.0
 */
final class GcsSessionFactory implements SessionFactoryInterface
{
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        return new GcsSessionPersistence(
            new GcsClient(
                self::httpClient($context),
                self::str($parameters, 'access_key'),
                self::str($parameters, 'secret_key'),
                self::str($parameters, 'bucket'),
                self::str($parameters, 'endpoint', 'https://storage.googleapis.com'),
                new Psr17Factory(),
            ),
            self::str($parameters, 'object_prefix', 'sessions/'),
        );
    }

    /** @param array<string, mixed> $parameters */
    private static function str(array $parameters, string $key, string $default = ''): string
    {
        $value = $parameters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function httpClient(Context $context): ClientInterface
    {
        try {
            $client = $context->getContainer()->get(ClientInterface::class);
        } catch (\Throwable) {
            $client = null;
        }

        if (!$client instanceof ClientInterface) {
            throw new RuntimeException(sprintf(
                '%s-backed sessions need a %s bound in the container -- none found. '
                . 'Bind your PSR-18 client, the same way quioteframework/filesystem-%s expects.',
                'GCS',
                ClientInterface::class,
                'gcs',
            ));
        }

        return $client;
    }
}
