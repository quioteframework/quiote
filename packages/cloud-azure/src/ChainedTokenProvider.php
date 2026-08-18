<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Quiote\Logging\Log;

/**
 * Tries each provider in order and answers the first token obtained, the way the official Azure
 * SDKs' `DefaultAzureCredential` chains workload identity, then the CLI, then further sources.
 * Falling through to the next provider is the designed behaviour here, not a degradation, so a
 * provider's failure is logged at debug rather than warning.
 */
final class ChainedTokenProvider implements AzureTokenProvider
{
    /** @param non-empty-list<AzureTokenProvider> $providers */
    public function __construct(private readonly array $providers)
    {
    }

    /** @inheritDoc */
    #[\Override]
    public function getToken(): string
    {
        $logger = Log::for($this);
        $failures = [];

        foreach ($this->providers as $provider) {
            $providerClass = $provider::class;
            try {
                return $provider->getToken();
            } catch (AzureStorageException $e) {
                $failures[] = sprintf('%s: %s', $providerClass, $e->getMessage());
                $logger->debug("{$providerClass} declined, trying the next credential in the chain: {$e->getMessage()}");
            }
        }

        throw new AzureStorageException('No credential in the chain could produce a token: ' . implode('; ', $failures));
    }
}
