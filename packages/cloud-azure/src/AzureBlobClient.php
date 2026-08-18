<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use DateTimeImmutable;
use Exception;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\ObjectListing;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectSummary;
use SimpleXMLElement;

/**
 * Minimal Azure Blob Storage REST client, deliberately not built on the
 * official `microsoft/azure-storage-blob` SDK (Microsoft stopped actively
 * developing it; a hand-rolled client against the documented REST API has
 * proven more maintainable in production). Only the operations the session
 * and filesystem backends need: ensure-container, get, put, delete,
 * get-properties and list. No chunked upload or snapshots.
 *
 * Those absent operations are still reachable: {@see request()} authorizes
 * the request the same way every other method does and hands back the raw
 * PSR-7 response, so a caller can implement the operation it needs without
 * reimplementing the authorization.
 *
 * Authorization itself, Shared Key or an Azure AD bearer token from
 * workload identity or the Azure CLI, is delegated to an
 * {@see AzureCredential}, not built in here.
 *
 * @see https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key
 */
final class AzureBlobClient
{
    private const string API_VERSION = '2023-11-03';
    private const int RETRY_MAX_ATTEMPTS = 3;
    private const int RETRY_BASE_DELAY_MS = 200;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $accountName,
        private readonly AzureCredential $credential,
        private readonly ?string $endpoint = null,
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {
    }

    /**
     * Creates the container, treating "already exists" as success.
     *
     * A 409 from Azure means another caller got there first, which is the desired end state, so
     * 201, 202 and 409 all return normally.
     *
     * @throws     AzureStorageException On any other status, or if the request could not be sent
     *             after the configured retries.
     */
    public function ensureContainerExists(string $container): void
    {
        $response = $this->send('PUT', "/{$container}", ['restype' => 'container']);
        if (!in_array($response->getStatusCode(), [201, 202, 409], true)) {
            throw $this->unexpectedStatus($response);
        }
    }

    /**
     * Returns the blob's contents, or null if Azure answers 404.
     *
     * A container that does not exist also answers 404, so it is indistinguishable from a
     * missing blob here.
     *
     * @throws     AzureStorageException On any other 4xx/5xx status, or a transport failure that
     *             survived the retries.
     */
    public function get(string $container, string $blob): ?string
    {
        $response = $this->send('GET', $this->blobPath($container, $blob));
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }

        return (string) $response->getBody();
    }

    /**
     * Creates or replaces a block blob in one request.
     *
     * The whole payload is sent in a single PUT with an
     * `application/octet-stream` content type; there is no chunked upload, so
     * the data must fit Azure's single-request block blob limit. The container
     * must already exist.
     *
     * @throws     AzureStorageException If Azure answers 4xx/5xx, or the request could not be
     *             sent after the retries.
     */
    public function put(string $container, string $blob, string $data): void
    {
        $response = $this->send('PUT', $this->blobPath($container, $blob), headers: [
            'x-ms-blob-type' => 'BlockBlob',
            'Content-Type' => 'application/octet-stream',
        ], body: $data);

        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }
    }

    /**
     * Deletes a blob, treating a missing one as success.
     *
     * A 404 returns normally so a delete is idempotent.
     *
     * @throws     AzureStorageException On any other 4xx/5xx status, or a transport failure that
     *             survived the retries.
     */
    public function delete(string $container, string $blob): void
    {
        $response = $this->send('DELETE', $this->blobPath($container, $blob));
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 404) {
            throw $this->unexpectedStatus($response);
        }
    }

    /**
     * Blob properties without transferring the body (Get Blob Properties), or
     * null if the blob does not exist.
     */
    public function head(string $container, string $blob): ?ObjectMetadata
    {
        $response = $this->send('HEAD', $this->blobPath($container, $blob));
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }

        return ObjectMetadata::fromResponse($response);
    }

    /**
     * Lists blobs in $container whose name starts with $prefix, one page at a time (List Blobs).
     *
     * $continuationToken must be null on the first call and, for a truncated result, the previous
     * call's {@see ObjectListing::$nextContinuationToken} verbatim on the next; it carries Azure's
     * own `NextMarker` and is opaque to a caller.
     *
     * @throws     AzureStorageException On any 4xx/5xx status, a transport failure that survived
     *             the retries, or a response body that was not the XML this expects.
     */
    public function listObjects(string $container, string $prefix = '', string $delimiter = '', ?string $continuationToken = null, int $maxKeys = 1000): ObjectListing
    {
        $query = ['restype' => 'container', 'comp' => 'list', 'maxresults' => (string) $maxKeys];
        if ($prefix !== '') {
            $query['prefix'] = $prefix;
        }
        if ($delimiter !== '') {
            $query['delimiter'] = $delimiter;
        }
        if ($continuationToken !== null) {
            $query['marker'] = $continuationToken;
        }

        $response = $this->send('GET', "/{$container}", $query);
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }

        return self::parseListing((string) $response->getBody());
    }

    private static function parseListing(string $body): ObjectListing
    {
        $document = @simplexml_load_string($body);
        if (!$document instanceof SimpleXMLElement) {
            throw new AzureStorageException('Azure returned a list response that was not valid XML.');
        }

        $objects = [];
        $commonPrefixes = [];
        if (isset($document->Blobs)) {
            foreach ($document->Blobs->Blob as $blob) {
                $properties = $blob->Properties;
                $length = (string) $properties->{'Content-Length'};
                $objects[] = new ObjectSummary(
                    (string) $blob->Name,
                    ctype_digit($length) ? (int) $length : null,
                    self::parseHttpDate((string) $properties->{'Last-Modified'}),
                    self::stripQuotes((string) $properties->Etag),
                );
            }

            foreach ($document->Blobs->BlobPrefix as $entry) {
                $commonPrefixes[] = (string) $entry->Name;
            }
        }

        $nextMarker = (string) $document->NextMarker;

        return new ObjectListing($objects, $commonPrefixes, $nextMarker !== '' ? $nextMarker : null);
    }

    private static function parseHttpDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private static function stripQuotes(string $value): ?string
    {
        $trimmed = trim($value, '"');

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Send an arbitrary signed request and return the raw response, for
     * operations this class does not model itself.
     *
     * $path is the full account-relative path, so `/{container}` addresses a
     * container and `/{container}/{blob}` a blob. Unlike {@see get()} and
     * friends this does not interpret the status code: a 404 or a 500 comes
     * back as a response, not as an exception, and only a transport-level
     * failure throws {@see AzureStorageException}. The retry-on-transient
     * behaviour of every other operation still applies.
     *
     * @param array<string, string> $query signed as part of the canonicalized resource
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, array $query = [], array $headers = [], ?string $body = null): ResponseInterface
    {
        return $this->send($method, $path, $query, $headers, $body);
    }

    private function blobPath(string $container, string $blob): string
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $blob)));

        return "/{$container}/{$encoded}";
    }

    private function origin(): string
    {
        return $this->endpoint ?? "https://{$this->accountName}.blob.core.windows.net";
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $query = [], array $headers = [], ?string $body = null): ResponseInterface
    {
        $lastResponse = null;

        for ($attempt = 1; $attempt <= self::RETRY_MAX_ATTEMPTS; $attempt++) {
            $uri = $this->origin() . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
            $requestHeaders = array_merge([
                'x-ms-date' => gmdate('D, d M Y H:i:s') . ' GMT',
                'x-ms-version' => self::API_VERSION,
                'Content-Length' => (string) ($body !== null ? strlen($body) : 0),
            ], $headers);
            $requestHeaders['Authorization'] = $this->credential->authorizationHeader($this->accountName, $method, $path, $query, $requestHeaders);

            $request = $this->psr17->createRequest($method, $uri);
            foreach ($requestHeaders as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            if ($body !== null) {
                $request = $request->withBody($this->psr17->createStream($body));
            }

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($attempt === self::RETRY_MAX_ATTEMPTS) {
                    throw new AzureStorageException("Azure Blob request failed: {$e->getMessage()}", 0, $e);
                }
                usleep(self::RETRY_BASE_DELAY_MS * 1000 * $attempt);
                continue;
            }

            $lastResponse = $response;
            if (!$this->isTransient($response->getStatusCode()) || $attempt === self::RETRY_MAX_ATTEMPTS) {
                return $response;
            }
            usleep(self::RETRY_BASE_DELAY_MS * 1000 * $attempt);
        }

        if ($lastResponse === null) {
            // Unreachable while RETRY_MAX_ATTEMPTS >= 1, but the loop bound is a
            // constant somebody could lower; better a named failure than a
            // TypeError on the way out.
            throw new AzureStorageException('No response from Azure Storage: the retry loop ran zero attempts.');
        }

        return $lastResponse;
    }

    private function isTransient(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    private function unexpectedStatus(ResponseInterface $response): AzureStorageException
    {
        return new AzureStorageException(sprintf('Azure Blob request failed with status %d: %s', $response->getStatusCode(), (string) $response->getBody()));
    }
}
