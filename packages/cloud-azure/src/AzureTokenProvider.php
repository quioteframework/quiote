<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Produces an Azure AD access token for whichever resource its implementation was built for,
 * caching and refreshing it however fits the source (a token exchange, a CLI call, a chain of
 * both). {@see STORAGE_RESOURCE} is every implementation's own default, since Blob/Table storage
 * is what {@see AzureCredentialFactory} builds one for; a caller that needs a token for a
 * different AAD-protected API (e.g. Log Analytics) passes its own resource/scope instead, via
 * {@see AzureTokenProviderFactory} or a provider's constructor directly.
 */
interface AzureTokenProvider
{
    /**
     * The `az account get-access-token --resource` form: an https URL ending in `/`. The
     * corresponding client-credentials `scope` (used by {@see WorkloadIdentityTokenProvider}) is
     * always this value with `.default` appended -- the same relationship Azure's own resource
     * and v2-endpoint scope identifiers have for every first-party API.
     */
    public const string STORAGE_RESOURCE = 'https://storage.azure.com/';

    /** @throws AzureStorageException If no token could be obtained. */
    public function getToken(): string;
}
