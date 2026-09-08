<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Reuses whatever identity a developer already authenticated with `az login`, by shelling out to
 * `az account get-access-token`. Meant for local development against a real storage account
 * without ever handing out an account key.
 *
 * The CLI already caches and refreshes its own token, so rather than parse its
 * locale-formatted `expiresOn` this re-invokes it on a short, fixed TTL: the extra call is
 * cheap and always correct, where parsing a datetime the CLI does not promise a stable format
 * for would not be.
 */
final class AzureCliTokenProvider implements AzureTokenProvider
{
    use CachesExpiringToken;
    use DecodesBearerTokenJson;

    private const int CACHE_TTL_SECONDS = 240;

    public function __construct(
        private readonly AzureCliProcessRunner $processRunner = new ProcOpenAzureCliProcessRunner(),
        private readonly string $resource = self::STORAGE_RESOURCE,
    ) {
    }

    /** @inheritDoc */
    #[\Override]
    public function getToken(): string
    {
        if (($cached = $this->cachedTokenOrNull()) !== null) {
            return $cached;
        }

        $output = $this->processRunner->run(['az', 'account', 'get-access-token', '--resource', $this->resource, '--output', 'json']);

        $decoded = self::decodeBearerToken($output, 'accessToken', '"az account get-access-token"', ', run "az login" first');

        return $this->cacheToken($decoded['token'], self::CACHE_TTL_SECONDS);
    }
}
