<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Azure Blob Storage REST client using Shared Key authentication —
 * deliberately not built on the official `microsoft/azure-storage-blob` SDK
 * (Microsoft stopped actively developing it; a hand-rolled client against
 * the documented REST + signing algorithm has proven more maintainable in
 * production). Only the four operations {@see AzureBlobSessionPersistence}
 * needs: ensure-container, get, put, delete. No chunked upload, snapshots,
 * or listing — session payloads are small enough for a single PUT.
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
        private readonly string $accountKey,
        private readonly ?string $endpoint = null,
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {
    }

    public function ensureContainerExists(string $container): void
    {
        $response = $this->send('PUT', "/{$container}", ['restype' => 'container']);
        if (!in_array($response->getStatusCode(), [201, 202, 409], true)) {
            throw $this->unexpectedStatus($response);
        }
    }

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

    public function delete(string $container, string $blob): void
    {
        $response = $this->send('DELETE', $this->blobPath($container, $blob));
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 404) {
            throw $this->unexpectedStatus($response);
        }
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

    /** @param array<string, string> $query @param array<string, string> $headers */
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
            $requestHeaders['Authorization'] = $this->sign($method, $path, $query, $requestHeaders);

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

        return $lastResponse;
    }

    private function isTransient(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    /** @param array<string, string> $query @param array<string, string> $headers */
    private function sign(string $method, string $path, array $query, array $headers): string
    {
        $contentLength = $headers['Content-Length'] ?? '';
        if ($contentLength === '0') {
            $contentLength = '';
        }

        $stringToSign = implode("\n", [
            $method,
            $headers['Content-Encoding'] ?? '',
            $headers['Content-Language'] ?? '',
            $contentLength,
            $headers['Content-MD5'] ?? '',
            $headers['Content-Type'] ?? '',
            '',
            $headers['If-Modified-Since'] ?? '',
            $headers['If-Match'] ?? '',
            $headers['If-None-Match'] ?? '',
            $headers['If-Unmodified-Since'] ?? '',
            $headers['Range'] ?? '',
            $this->canonicalizedHeaders($headers) . $this->canonicalizedResource($path, $query),
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey, true), true));

        return "SharedKey {$this->accountName}:{$signature}";
    }

    /** @param array<string, string> $headers */
    private function canonicalizedHeaders(array $headers): string
    {
        $msHeaders = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'x-ms-')) {
                $msHeaders[$lower] = preg_replace('/\s+/', ' ', trim($value));
            }
        }
        ksort($msHeaders);

        $canonical = '';
        foreach ($msHeaders as $name => $value) {
            $canonical .= "{$name}:{$value}\n";
        }

        return $canonical;
    }

    /** @param array<string, string> $query */
    private function canonicalizedResource(string $path, array $query): string
    {
        $canonical = "/{$this->accountName}{$path}";

        $lowerQuery = [];
        foreach ($query as $name => $value) {
            $lowerQuery[strtolower($name)] = $value;
        }
        ksort($lowerQuery);
        foreach ($lowerQuery as $name => $value) {
            $canonical .= "\n{$name}:{$value}";
        }

        return $canonical;
    }

    private function unexpectedStatus(ResponseInterface $response): AzureStorageException
    {
        return new AzureStorageException(sprintf('Azure Blob request failed with status %d: %s', $response->getStatusCode(), (string) $response->getBody()));
    }
}
