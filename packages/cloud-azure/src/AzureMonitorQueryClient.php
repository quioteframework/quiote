<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Minimal Azure Monitor Query REST client: one KQL query against one Log Analytics workspace,
 * nothing else. Authenticates with a bearer {@see AzureTokenProvider} scoped to
 * `https://api.loganalytics.io/` -- build one via {@see AzureTokenProviderFactory}, not
 * {@see AzureCredentialFactory}, since this API takes no storage-account-key credential at all.
 *
 * @see https://learn.microsoft.com/en-us/rest/api/loganalytics/dataaccess/query/get
 */
final class AzureMonitorQueryClient implements AzureMonitorQueryClientInterface
{
    private const int RETRY_MAX_ATTEMPTS = 3;
    private const int RETRY_BASE_DELAY_MS = 200;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly AzureTokenProvider $tokenProvider,
        private readonly string $workspaceId,
        private readonly string $endpoint = 'https://api.loganalytics.io',
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {
    }

    /** @inheritDoc */
    #[\Override]
    public function query(string $kql): array
    {
        $body = json_encode(['query' => $kql], JSON_THROW_ON_ERROR);
        $response = $this->send($body);

        try {
            $payload = json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AzureStorageException("Azure Monitor returned a response that was not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($payload) || !isset($payload['tables']) || !is_array($payload['tables'])) {
            return [];
        }
        $table = $payload['tables'][0] ?? null;
        if (!is_array($table) || !isset($table['columns'], $table['rows']) || !is_array($table['columns']) || !is_array($table['rows'])) {
            return [];
        }

        $columnNames = array_map(
            static fn(mixed $column): string => is_array($column) && is_string($column['name'] ?? null) ? $column['name'] : '',
            $table['columns'],
        );

        $rows = [];
        foreach ($table['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = array_pad(array_slice(array_values($row), 0, count($columnNames)), count($columnNames), null);
            $rows[] = array_combine($columnNames, $values);
        }

        return $rows;
    }

    private function send(string $body): string
    {
        $uri = rtrim($this->endpoint, '/') . "/v1/workspaces/{$this->workspaceId}/query";
        $lastResponseBody = null;
        $lastResponseStatus = null;

        for ($attempt = 1; $attempt <= self::RETRY_MAX_ATTEMPTS; $attempt++) {
            $request = $this->psr17->createRequest('POST', $uri)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Authorization', 'Bearer ' . $this->tokenProvider->getToken())
                ->withBody($this->psr17->createStream($body));

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                if ($attempt === self::RETRY_MAX_ATTEMPTS) {
                    throw new AzureStorageException("Azure Monitor query failed: {$e->getMessage()}", 0, $e);
                }
                usleep(self::RETRY_BASE_DELAY_MS * 1000 * $attempt);
                continue;
            }

            $status = $response->getStatusCode();
            $lastResponseBody = (string) $response->getBody();
            $lastResponseStatus = $status;
            if ($status >= 200 && $status < 300) {
                return $lastResponseBody;
            }
            if (!$this->isTransient($status) || $attempt === self::RETRY_MAX_ATTEMPTS) {
                throw new AzureStorageException(sprintf('Azure Monitor query failed with status %d: %s', $status, $lastResponseBody));
            }
            usleep(self::RETRY_BASE_DELAY_MS * 1000 * $attempt);
        }

        // Unreachable while RETRY_MAX_ATTEMPTS >= 1, but the loop bound is a constant somebody
        // could lower; better a named failure than falling off the end without a return.
        throw new AzureStorageException(sprintf(
            'No response from Azure Monitor: the retry loop ran zero attempts (last status: %s).',
            $lastResponseStatus !== null ? (string) $lastResponseStatus : 'none',
        ));
    }

    private function isTransient(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }
}
