<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Produces an Azure AD access token for the `https://storage.azure.com/` resource, caching and
 * refreshing it however fits the source (a token exchange, a CLI call, a chain of both).
 */
interface AzureTokenProvider
{
    /** @throws AzureStorageException If no token could be obtained. */
    public function getToken(): string;
}
