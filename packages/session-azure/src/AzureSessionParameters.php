<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Psr\Http\Client\ClientInterface;
use Quiote\Context;
use RuntimeException;

/**
 * Shared slot-parameter handling for the two Azure session factories. Factory
 * config arrives untyped, and both factories need the same narrowing and the
 * same PSR-18 lookup.
 *
 * @internal
 */
final class AzureSessionParameters
{
    /** @param array<string, mixed> $parameters */
    public static function str(array $parameters, string $key, string $default = ''): string
    {
        $value = $parameters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function httpClient(Context $context, string $service): ClientInterface
    {
        $client = $context->getContainer()->tryGet(ClientInterface::class);

        if (!$client instanceof ClientInterface) {
            throw new RuntimeException(sprintf(
                'Azure %s-backed sessions need a %s bound in the container -- none found. '
                . 'Bind your PSR-18 client, the same way quioteframework/filesystem-azure expects.',
                $service,
                ClientInterface::class,
            ));
        }

        return $client;
    }
}
