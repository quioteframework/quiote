<?php

declare(strict_types=1);

namespace Quiote\Storage\Gcs;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Storage\ObjectMetadata;
use Quiote\Storage\ObjectStoreClientInterface;

/**
 * Minimal Google Cloud Storage REST client authenticating with an HMAC key
 * pair (GCS's "interoperability" auth mode, meant for exactly this kind of
 * S3-like tool) rather than a service-account OAuth2/JWT flow — no
 * `google/cloud-storage` dependency, no token exchange round-trip, just the
 * operations a session or filesystem backend needs against the XML API: get,
 * put, delete and head a single object.
 *
 * Anything beyond those — listing a bucket, resumable upload, ACLs — is
 * deliberately absent, but reachable: {@see request()} performs the HMAC
 * signing and hands back the raw PSR-7 response, so a caller can implement
 * the operation it needs without reimplementing the signature.
 *
 * @see https://cloud.google.com/storage/docs/authentication/hmackeys
 * @see https://cloud.google.com/storage/docs/migrating#migration-simple
 */
final class GcsClient implements ObjectStoreClientInterface
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

    /**
     * Object metadata without transferring the body, or null if the object
     * does not exist.
     */
    public function head(string $object): ?ObjectMetadata
    {
        $response = $this->send('HEAD', $object);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }

        return ObjectMetadata::fromResponse($response);
    }

    /**
     * Send an arbitrary signed request to this client's bucket and return the
     * raw response, for operations this class does not model itself. The
     * canonical use is listing:
     *
     *     $response = $client->request('GET', '', ['prefix' => 'files/', 'delimiter' => '/']);
     *     $xml = simplexml_load_string((string) $response->getBody());
     *
     * An empty $object addresses the bucket itself. Unlike {@see get()} and
     * friends this does not interpret the status code: a 404 or a 500 comes
     * back as a response, not as an exception, and only a transport-level
     * failure throws {@see GcsStorageException}.
     *
     * $query is appended to the URL but not signed, which is what the v1
     * signing scheme wants for list parameters (prefix, delimiter, marker,
     * max-keys). Sub-resources that do belong in the signature — `?acl`,
     * `?versions` and friends — are therefore not usable through this method.
     *
     * @param array<string, string> $query
     */
    public function request(string $method, string $object = '', array $query = [], ?string $body = null, string $contentType = ''): ResponseInterface
    {
        return $this->send($method, $object, $body, $contentType, $query);
    }

    private function resourcePath(string $object): string
    {
        if ($object === '') {
            return "/{$this->bucket}";
        }

        $encodedObject = implode('/', array_map(rawurlencode(...), explode('/', $object)));

        return "/{$this->bucket}/{$encodedObject}";
    }

    /** @param array<string, string> $query */
    private function send(string $method, string $object, ?string $body = null, string $contentType = '', array $query = []): ResponseInterface
    {
        $path = $this->resourcePath($object);
        $date = gmdate('D, d M Y H:i:s') . ' GMT';

        $stringToSign = implode("\n", [$method, '', $contentType, $date, $path]);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $uri = $this->endpoint . $path
            . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $request = $this->psr17
            ->createRequest($method, $uri)
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
