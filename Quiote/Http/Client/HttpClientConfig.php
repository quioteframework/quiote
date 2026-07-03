<?php

namespace Quiote\Http\Client;

use Psr\Http\Client\ClientInterface;

/**
 * Mutable, fluent configuration for a named HTTP client, populated inside a
 * {@see HttpClientFactory::configure()} callback (the dotnet
 * `AddHttpClient("name", c => ...)` analogue). Turned into an immutable
 * {@see HttpClient} once, then memoized by the factory.
 */
final class HttpClientConfig
{
    /** @var array<string,string> default headers applied to every request */
    private array $headers = [];

    private string $baseUri = '';

    private ?ClientInterface $transport = null;

    private int $retries = 0;

    private int $retryBaseDelayMs = 100;

    /** Base URI prepended to relative request paths (e.g. "https://api.example.com"). */
    public function baseUri(string $baseUri): self
    {
        $this->baseUri = rtrim($baseUri, '/');
        return $this;
    }

    /** A default header sent with every request unless the request already sets it. */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @param array<string,string> $headers */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /** Override the underlying PSR-18 transport for this client (default: {@see TransportFactory::default()}). */
    public function transport(ClientInterface $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    /**
     * Retry transient failures (network errors, HTTP 429, HTTP 5xx) up to
     * $attempts extra times, with exponential backoff from $baseDelayMs.
     */
    public function retry(int $attempts, int $baseDelayMs = 100): self
    {
        $this->retries = max(0, $attempts);
        $this->retryBaseDelayMs = max(0, $baseDelayMs);
        return $this;
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getTransport(): ClientInterface
    {
        return $this->transport ??= TransportFactory::default();
    }

    public function getRetries(): int
    {
        return $this->retries;
    }

    public function getRetryBaseDelayMs(): int
    {
        return $this->retryBaseDelayMs;
    }
}
