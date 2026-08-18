<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Azure AD authentication: every request carries `Authorization: Bearer {token}`, the token
 * itself coming from an {@see AzureTokenProvider} (workload identity, the Azure CLI, or a chain
 * of both). No storage account key is ever read or needed.
 */
final class BearerCredential implements AzureCredential
{
    public function __construct(private readonly AzureTokenProvider $tokenProvider)
    {
    }

    /** @inheritDoc */
    #[\Override]
    public function authorizationHeader(string $accountName, string $method, string $path, array $query, array $headers): string
    {
        return 'Bearer ' . $this->tokenProvider->getToken();
    }
}
