<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Produces the `Authorization` header value for one {@see AzureBlobClient} request.
 *
 * Shared Key needs the full request shape to compute a signature; a bearer-token strategy needs
 * none of it and answers the same header for every call. Both fit through this one seam so
 * {@see AzureBlobClient::send()} never branches on which kind of credential it was given.
 */
interface AzureCredential
{
    /**
     * @param array<string, string> $query   Signed as part of the canonicalized resource.
     * @param array<string, string> $headers The request's headers, including `x-ms-date` and
     *                                        `x-ms-version`, before `Authorization` is added.
     */
    public function authorizationHeader(string $accountName, string $method, string $path, array $query, array $headers): string;
}
