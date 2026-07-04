<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Azure Table Storage REST client using the Table service's
 * "Shared Key Lite" authentication scheme — a cheaper option than Blob
 * Storage for small key/value-shaped session payloads (no per-account
 * container needed; entities are addressed by table + partition/row key).
 * Only the three entity operations {@see AzureTableSessionPersistence}
 * needs: ensure-table, upsert entity, get entity, delete entity.
 *
 * @see https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key
 */
final class AzureTableClient
{
    private const string API_VERSION = '2020-12-06';
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

    public function ensureTableExists(string $table): void
    {
        $response = $this->send('POST', '/Tables', body: json_encode(['TableName' => $table], JSON_THROW_ON_ERROR));
        $status = $response->getStatusCode();
        if ($status !== 201 && $status !== 409) {
            throw $this->unexpectedStatus($response);
        }
    }

    /** @return array<string, mixed>|null */
    public function get(string $table, string $partitionKey, string $rowKey): ?array
    {
        $response = $this->send('GET', $this->entityPath($table, $partitionKey, $rowKey));
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }

        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $properties */
    public function upsert(string $table, string $partitionKey, string $rowKey, array $properties): void
    {
        $body = array_merge(['PartitionKey' => $partitionKey, 'RowKey' => $rowKey], $properties);
        $response = $this->send('PUT', $this->entityPath($table, $partitionKey, $rowKey), body: json_encode($body, JSON_THROW_ON_ERROR));
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }
    }

    public function delete(string $table, string $partitionKey, string $rowKey): void
    {
        $response = $this->send('DELETE', $this->entityPath($table, $partitionKey, $rowKey), extraHeaders: ['If-Match' => '*']);
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 404) {
            throw $this->unexpectedStatus($response);
        }
    }

    private function entityPath(string $table, string $partitionKey, string $rowKey): string
    {
        return sprintf("/%s(PartitionKey='%s',RowKey='%s')", $table, $this->escapeKey($partitionKey), $this->escapeKey($rowKey));
    }

    private function escapeKey(string $key): string
    {
        return str_replace("'", "''", $key);
    }

    private function origin(): string
    {
        return $this->endpoint ?? "https://{$this->accountName}.table.core.windows.net";
    }

    /** @param array<string, string> $extraHeaders */
    private function send(string $method, string $path, array $extraHeaders = [], ?string $body = null): ResponseInterface
    {
        $lastResponse = null;

        for ($attempt = 1; $attempt <= self::RETRY_MAX_ATTEMPTS; $attempt++) {
            $date = gmdate('D, d M Y H:i:s') . ' GMT';
            $headers = array_merge([
                'x-ms-date' => $date,
                'x-ms-version' => self::API_VERSION,
                'Accept' => 'application/json;odata=nometadata',
                'Content-Type' => 'application/json',
            ], $extraHeaders);
            $headers['Authorization'] = $this->sign($date, $path);

            $request = $this->psr17->createRequest($method, $this->origin() . $path);
            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
            if ($body !== null) {
                $request = $request->withBody($this->psr17->createStream($body));
            }

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($attempt === self::RETRY_MAX_ATTEMPTS) {
                    throw new AzureStorageException("Azure Table request failed: {$e->getMessage()}", 0, $e);
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

    private function sign(string $date, string $path): string
    {
        $stringToSign = "{$date}\n/{$this->accountName}{$path}";
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey, true), true));

        return "SharedKeyLite {$this->accountName}:{$signature}";
    }

    private function unexpectedStatus(ResponseInterface $response): AzureStorageException
    {
        return new AzureStorageException(sprintf('Azure Table request failed with status %d: %s', $response->getStatusCode(), (string) $response->getBody()));
    }
}
