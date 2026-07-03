<?php

namespace Quiote\Http\Client;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Quiote\Http\Client\Exception\NetworkException;
use Quiote\Http\Client\Exception\RequestException;

/**
 * Zero-dependency PSR-18 transport built on ext-curl and the Nyholm PSR-17
 * factory (already a hard framework dependency). This is the default transport
 * so the HTTP client abstraction works out of the box with no extra Composer
 * package; {@see TransportFactory} prefers Guzzle when it is installed.
 *
 * Failure mapping follows PSR-18: a connectivity failure (DNS, refused, reset,
 * timeout) throws {@see NetworkException}; an unusable request throws
 * {@see RequestException}; a real HTTP response — including 4xx/5xx — is
 * returned, never thrown (status handling is the caller's job).
 */
final class CurlTransport implements ClientInterface
{
    // curl error codes treated as network-layer failures.
    private const NETWORK_ERRORS = [
        \CURLE_COULDNT_RESOLVE_HOST,
        \CURLE_COULDNT_CONNECT,
        \CURLE_OPERATION_TIMEOUTED,
        \CURLE_GOT_NOTHING,
        \CURLE_RECV_ERROR,
        \CURLE_SEND_ERROR,
        \CURLE_SSL_CONNECT_ERROR,
    ];

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory = new Psr17Factory(),
        private readonly StreamFactoryInterface $streamFactory = new Psr17Factory(),
        private readonly float $timeoutSeconds = 30.0,
        private readonly float $connectTimeoutSeconds = 10.0,
    ) {
        if (!\function_exists('curl_init')) {
            throw new \RuntimeException('CurlTransport requires the curl extension.');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = (string) $request->getUri();
        if ($uri === '') {
            throw new RequestException('Cannot send a request with an empty URI.', $request);
        }

        $ch = curl_init();
        $responseHeaders = [];

        curl_setopt_array($ch, [
            \CURLOPT_URL => $uri,
            \CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            \CURLOPT_HTTPHEADER => $this->flattenHeaders($request),
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeoutSeconds * 1000),
            \CURLOPT_HEADERFUNCTION => function ($_ch, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $trimmed = trim($headerLine);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[trim($name)][] = trim($value);
                }
                return $len;
            },
        ]);

        $body = $request->getBody();
        if ($body->getSize() !== 0) {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            curl_setopt($ch, \CURLOPT_POSTFIELDS, (string) $body);
        }

        $rawBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        // Note: no curl_close() — deprecated + no-op since PHP 8.0; the handle
        // is freed when $ch goes out of scope at method return.

        if ($errno !== 0 || $rawBody === false) {
            if (in_array($errno, self::NETWORK_ERRORS, true)) {
                throw new NetworkException(sprintf('cURL network error (%d): %s', $errno, $error), $request);
            }
            throw new RequestException(sprintf('cURL error (%d): %s', $errno, $error), $request);
        }

        $response = $this->responseFactory->createResponse($status)
            ->withBody($this->streamFactory->createStream((string) $rawBody));
        foreach ($responseHeaders as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        return $response;
    }

    /** @return list<string> "Name: value" lines for CURLOPT_HTTPHEADER */
    private function flattenHeaders(RequestInterface $request): array
    {
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }
        return $lines;
    }
}
