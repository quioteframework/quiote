<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * A single KQL query against one Log Analytics workspace. Split from {@see AzureMonitorQueryClient}
 * so a consumer like `quioteframework/replay-azure`'s `LogAnalyticsIndex` depends on the one
 * operation it actually calls, not the concrete REST client -- the same shape
 * {@see AzureTokenProvider} already gives token providers.
 */
interface AzureMonitorQueryClientInterface
{
    /**
     * Runs $kql and returns its primary result table as one row per array, keyed by column name.
     * An empty result set (including a query with no `tables` at all) is an empty list, not an
     * error.
     *
     * @return list<array<string, mixed>>
     * @throws AzureStorageException On a non-2xx response, a transport failure that survived the
     *         retries, or a response body that was not the JSON shape this expects.
     */
    public function query(string $kql): array;
}
