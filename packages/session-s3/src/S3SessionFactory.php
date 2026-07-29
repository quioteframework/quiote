<?php

declare(strict_types=1);

namespace Quiote\Storage\S3;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Quiote\Context;
use Quiote\Session\SessionFactoryInterface;
use Quiote\Session\SessionPersistenceInterface;
use RuntimeException;

/**
 * `session` slot factory for {@see S3SessionPersistence}.
 *
 * ```yaml
 * session:
 *   class: Quiote\Storage\S3\S3SessionFactory
 *   params:
 *     region: eu-west-1
 *     bucket: my-app-sessions
 *     access_key_id: '%env(AWS_ACCESS_KEY_ID)%'
 *     secret_access_key: '%env(AWS_SECRET_ACCESS_KEY)%'
 *     key_prefix: 'sessions/'
 *     # endpoint: 'https://minio.internal'   # any S3-compatible service
 * ```
 *
 * Bring your own PSR-18 client, bound in the container -- the same contract
 * quioteframework/filesystem-s3 uses. The bucket must already exist; creation
 * and lifecycle belong to infrastructure tooling, not a session backend.
 *
 * @since      3.0.0
 */
final class S3SessionFactory implements SessionFactoryInterface
{
    public function createPersistence(Context $context, array $parameters): SessionPersistenceInterface
    {
        $endpoint = self::str($parameters, 'endpoint');

        return new S3SessionPersistence(
            new S3Client(
                self::httpClient($context),
                self::str($parameters, 'region', 'us-east-1'),
                self::str($parameters, 'access_key_id'),
                self::str($parameters, 'secret_access_key'),
                self::str($parameters, 'bucket'),
                $endpoint !== '' ? $endpoint : null,
                new Psr17Factory(),
            ),
            self::str($parameters, 'key_prefix', 'sessions/'),
        );
    }

    /** @param array<string, mixed> $parameters */
    private static function str(array $parameters, string $key, string $default = ''): string
    {
        $value = $parameters[$key] ?? null;

        return is_string($value) ? $value : $default;
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
                'S3',
                ClientInterface::class,
                's3',
            ));
        }

        return $client;
    }
}
