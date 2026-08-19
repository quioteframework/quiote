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
    private const int CACHE_TTL_SECONDS = 240;

    private ?string $cachedToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly AzureCliProcessRunner $processRunner = new ProcOpenAzureCliProcessRunner(),
        private readonly string $resource = self::STORAGE_RESOURCE,
    ) {
    }

    /** @inheritDoc */
    #[\Override]
    public function getToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->expiresAt) {
            return $this->cachedToken;
        }

        $output = $this->processRunner->run(['az', 'account', 'get-access-token', '--resource', $this->resource, '--output', 'json']);

        try {
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AzureStorageException("\"az account get-access-token\" did not return valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($payload) || !isset($payload['accessToken']) || !is_string($payload['accessToken'])) {
            throw new AzureStorageException('"az account get-access-token" response had no "accessToken", run "az login" first.');
        }

        $this->cachedToken = $payload['accessToken'];
        $this->expiresAt = time() + self::CACHE_TTL_SECONDS;

        return $this->cachedToken;
    }
}
