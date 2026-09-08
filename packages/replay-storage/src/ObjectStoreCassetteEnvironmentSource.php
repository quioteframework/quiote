<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Storage;

use Quiote\Environment\EnvironmentSourceInterface;
use Quiote\Storage\ListableObjectStoreClientInterface;

/**
 * Lists the distinct environment names replay cassettes have ever been partitioned under in an
 * object-store-backed {@see ObjectStoreCassetteStore}'s container -- a shallow, delimited
 * `listObjects()` one level above {@see CassetteKeyScheme::dayPrefix()}, not a scan of every
 * cassette. Registered as an {@see EnvironmentSourceInterface} by `quioteframework/replay-azure`
 * (and any other object-store binding of `quioteframework/replay-storage`), so `env:list` can show
 * what `replay.store.azure.env`/`core.environment` values a reader might point `cassette:list` at,
 * without `cassette:list` itself knowing anything about environments.
 */
final readonly class ObjectStoreCassetteEnvironmentSource implements EnvironmentSourceInterface
{
    public function __construct(
        private ListableObjectStoreClientInterface $client,
        private CassetteKeyScheme $keyScheme,
        private string $storeAlias,
    ) {
    }

    public function label(): string
    {
        return "replay cassettes ({$this->storeAlias})";
    }

    /** @return list<string> */
    public function listEnvironments(): array
    {
        $rootPrefix = $this->keyScheme->rootPrefix();
        $environments = [];
        $continuationToken = null;
        do {
            $listing = $this->client->listObjects($rootPrefix, '/', $continuationToken);
            foreach ($listing->commonPrefixes as $commonPrefix) {
                $environments[] = rtrim(substr($commonPrefix, strlen($rootPrefix)), '/');
            }
            $continuationToken = $listing->nextContinuationToken;
        } while ($continuationToken !== null);

        $unique = array_values(array_unique(array_filter($environments, static fn(string $env): bool => $env !== '')));
        sort($unique);

        return $unique;
    }
}
