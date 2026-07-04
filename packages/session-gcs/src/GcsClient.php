<?php

declare(strict_types=1);

namespace Quiote\Storage\Gcs;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Google Cloud Storage REST client authenticating with an HMAC key
 * pair (GCS's "interoperability" auth mode, meant for exactly this kind of
 * S3-like tool) rather than a service-account OAuth2/JWT flow — no
 * `google/cloud-storage` dependency, no token exchange round-trip, just the
 * three operations a session backend needs against the XML API.
 *
 * @see https://cloud.google.com/storage/docs/authentication/hmackeys
 * @see https://cloud.google.com/storage/docs/migrating#migration-simple
 */
final class GcsClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $bucket,
        private readonly string $endpoint = 'https://storage.googleapis.com',
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {
    }

    public function get(string $object): ?string
    {
        $response = $this->send('GET', $object);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }

        return (string) $response->getBody();
    }

    public function put(string $object, string $body): void
    {
        $response = $this->send('PUT', $object, $body, 'application/octet-stream');
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }
    }

    public function delete(string $object): void
    {
        $response = $this->send('DELETE', $object);
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 404) {
            throw $this->unexpectedStatus($response);
        }
    }

    private function resourcePath(string $object): string
    {
        $encodedObject = implode('/', array_map(rawurlencode(...), explode('/', $object)));

        return "/{$this->bucket}/{$encodedObject}";
    }

    private function send(string $method, string $object, ?string $body = null, string $contentType = ''): ResponseInterface
    {
        $path = $this->resourcePath($object);
        $date = gmdate('D, d M Y H:i:s') . ' GMT';

        $stringToSign = implode("\n", [$method, '', $contentType, $date, $path]);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $request = $this->psr17
            ->createRequest($method, $this->endpoint . $path)
            ->withHeader('Date', $date)
            ->withHeader('Authorization', "GOOG1 {$this->accessKey}:{$signature}");
        if ($contentType !== '') {
            $request = $request->withHeader('Content-Type', $contentType);
        }
        if ($body !== null) {
            $request = $request->withBody($this->psr17->createStream($body));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new GcsStorageException("GCS request failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function unexpectedStatus(ResponseInterface $response): GcsStorageException
    {
        return new GcsStorageException(sprintf('GCS request failed with status %d: %s', $response->getStatusCode(), (string) $response->getBody()));
    }
}
