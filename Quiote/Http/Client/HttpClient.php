<?php

namespace Quiote\Http\Client;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Telemetry\SpanKind;
use Quiote\Telemetry\Trace;

/**
 * The framework HTTP client: a PSR-18 `ClientInterface` wrapping an underlying
 * transport, adding base-URI resolution, default headers, a transient-failure
 * retry policy, ergonomic verb helpers, and — the payoff for the whole
 * abstraction — the central egress seam for telemetry. Every outbound request
 * opens a CLIENT-kind span and injects W3C `traceparent` so downstream services
 * continue the trace (the outbound half of the request-tracing story,
 * previously blocked on the absence of exactly this client).
 *
 * Obtain instances via {@see HttpClientFactory} (memoized, named) rather than
 * constructing directly, so a worker reuses one client per name for its
 * lifetime instead of rebuilding on every call.
 */
final class HttpClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $transport,
        private readonly string $baseUri = '',
        /** @var array<string,string> */
        private readonly array $defaultHeaders = [],
        private readonly int $retries = 0,
        private readonly int $retryBaseDelayMs = 100,
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {}

    public static function fromConfig(HttpClientConfig $config): self
    {
        return new self(
            $config->getTransport(),
            $config->getBaseUri(),
            $config->getHeaders(),
            $config->getRetries(),
            $config->getRetryBaseDelayMs(),
        );
    }

    /** PSR-18 entry point. Applies default headers, telemetry, and the retry policy. */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->sendWithTelemetry($this->applyDefaults($request));
    }

    /**
     * Ergonomic request builder.
     * @param array{headers?: array<string,string>, body?: string} $options
     */
    public function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        $request = $this->psr17->createRequest($method, $this->resolveUri($uri));
        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (isset($options['body'])) {
            $request = $request->withBody($this->psr17->createStream($options['body']));
        }
        return $this->sendRequest($request);
    }

    /** @param array{headers?: array<string,string>} $options */
    public function get(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('GET', $uri, $options);
    }

    /** @param array{headers?: array<string,string>, body?: string} $options */
    public function post(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('POST', $uri, $options);
    }

    /** @param array{headers?: array<string,string>, body?: string} $options */
    public function put(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $options);
    }

    /** @param array{headers?: array<string,string>} $options */
    public function delete(string $uri, array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, $options);
    }

    private function applyDefaults(RequestInterface $request): RequestInterface
    {
        // Rebase a request built against a relative URI onto the client's base URI.
        $uri = $request->getUri();
        if ($this->baseUri !== '' && $uri->getHost() === '') {
            $request = $request->withUri($this->psr17->createUri($this->resolveUri((string) $uri)));
        }
        foreach ($this->defaultHeaders as $name => $value) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }
        return $request;
    }

    private function resolveUri(string $uri): string
    {
        if ($this->baseUri === '' || preg_match('#^https?://#i', $uri)) {
            return $uri;
        }
        return $this->baseUri . '/' . ltrim($uri, '/');
    }

    private function sendWithTelemetry(RequestInterface $request): ResponseInterface
    {
        if (!Trace::enabled()) {
            return $this->sendWithRetry($request);
        }

        $span = Trace::span('Quiote.Http.Client', 'HTTP ' . $request->getMethod(), [
            'http.request.method' => $request->getMethod(),
            'url.full' => (string) $request->getUri(),
            'server.address' => $request->getUri()->getHost(),
        ], SpanKind::Client);

        try {
            $request = $this->injectTraceContext($request);
            $response = $this->sendWithRetry($request);
            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            if ($response->getStatusCode() >= 500) {
                $span->setStatusError('HTTP ' . $response->getStatusCode());
            }
            return $response;
        } catch (\Throwable $e) {
            $span->recordException($e)->setStatusError($e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    /** Inject W3C traceparent/tracestate onto the outbound request (no-op if the SDK/propagator is unavailable). */
    private function injectTraceContext(RequestInterface $request): RequestInterface
    {
        if (!class_exists(\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::class)) {
            return $request;
        }
        try {
            \OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance()
                ->inject($request, new \Quiote\Telemetry\Psr7HeaderSetter());
        } catch (\Throwable) {
            // Propagation is best-effort; a failure here must never break the request.
        }
        return $request;
    }

    private function sendWithRetry(RequestInterface $request): ResponseInterface
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->sendRequest($request);
                if ($this->shouldRetryStatus($response->getStatusCode()) && $attempt < $this->retries) {
                    $this->backoff($attempt++);
                    continue;
                }
                return $response;
            } catch (NetworkExceptionInterface $e) {
                if ($attempt >= $this->retries) {
                    throw $e;
                }
                $this->backoff($attempt++);
            }
        }
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function backoff(int $attempt): void
    {
        if ($this->retryBaseDelayMs <= 0) {
            return;
        }
        // Exponential: base * 2^attempt, in microseconds.
        usleep($this->retryBaseDelayMs * 1000 * (2 ** $attempt));
    }
}
