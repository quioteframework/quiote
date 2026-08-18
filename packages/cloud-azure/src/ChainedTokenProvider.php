<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tries each provider in order and answers the first token obtained, the way the official Azure
 * SDKs' `DefaultAzureCredential` chains workload identity, then the CLI, then further sources.
 * Falling through to the next provider is the designed behaviour here, not a degradation, so a
 * provider's failure is logged at debug rather than warning.
 */
final class ChainedTokenProvider implements AzureTokenProvider
{
    /**
     * @param non-empty-list<AzureTokenProvider> $providers
     * @param LoggerInterface $logger PSR-3, so a Quiote application can pass its own
     *        `Quiote\Logging\Log::for(...)` (it already implements the interface) without this
     *        package needing the framework as a dependency. Defaults to discarding everything.
     */
    public function __construct(private readonly array $providers, private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /** @inheritDoc */
    #[\Override]
    public function getToken(): string
    {
        $failures = [];

        foreach ($this->providers as $provider) {
            $providerClass = $provider::class;
            try {
                return $provider->getToken();
            } catch (AzureStorageException $e) {
                $failures[] = sprintf('%s: %s', $providerClass, $e->getMessage());
                $this->logger->debug("{$providerClass} declined, trying the next credential in the chain: {$e->getMessage()}");
            }
        }

        throw new AzureStorageException('No credential in the chain could produce a token: ' . implode('; ', $failures));
    }
}
