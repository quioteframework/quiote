<?php

declare(strict_types=1);

namespace Quiote\Storage\S3;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal S3 REST client using AWS Signature Version 4 — deliberately not
 * built on `aws/aws-sdk-php` (a heavy dependency pulling in a client for
 * every AWS service) for the operations a session or filesystem backend
 * needs: get, put, delete and head a single object. Path-style requests, so
 * `endpoint` also works against any S3-compatible service (MinIO, etc). The
 * bucket is assumed to already exist — bucket lifecycle is normally managed
 * outside the app (IaC), unlike Azure's implicit per-account containers.
 *
 * Anything beyond those four operations — ListObjectsV2, multipart upload,
 * tagging — is deliberately absent, but reachable: {@see request()} performs
 * the SigV4 signing and hands back the raw PSR-7 response, so a caller can
 * implement the operation it needs without reimplementing the signature.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html
 */
final class S3Client
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $region,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $bucket,
        private readonly ?string $endpoint = null,
        private readonly Psr17Factory $psr17 = new Psr17Factory(),
    ) {
    }

    public function get(string $key): ?string
    {
        $response = $this->send('GET', $key);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }

        return (string) $response->getBody();
    }

    public function put(string $key, string $body): void
    {
        $response = $this->send('PUT', $key, $body);
        if ($response->getStatusCode() >= 400) {
            throw $this->unexpectedStatus($response);
        }
    }

    public function delete(string $key): void
    {
        $response = $this->send('DELETE', $key);
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 404) {
            throw $this->unexpectedStatus($response);
        }
    }

    /**
     * Object metadata without transferring the body, or null if the object
     * does not exist.
     */
    public function head(string $key): ?ObjectMetadata
    {
        $response = $this->send('HEAD', $key);
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
     *     $response = $client->request('GET', '', ['list-type' => '2', 'prefix' => 'files/', 'delimiter' => '/']);
     *     $xml = simplexml_load_string((string) $response->getBody());
     *
     * An empty $key addresses the bucket itself. Unlike {@see get()} and
     * friends this does not interpret the status code: a 404 or a 500 comes
     * back as a response, not as an exception, and only a transport-level
     * failure throws {@see S3StorageException}.
     *
     * @param array<string, string> $query query parameters, signed as part of the request
     */
    public function request(string $method, string $key = '', array $query = [], ?string $body = null): ResponseInterface
    {
        return $this->send($method, $key, $body, $query);
    }

    private function origin(): string
    {
        return $this->endpoint ?? "https://s3.{$this->region}.amazonaws.com";
    }

    private function canonicalUri(string $key): string
    {
        if ($key === '') {
            return '/' . $this->bucket;
        }

        $encodedKey = implode('/', array_map(rawurlencode(...), explode('/', $key)));

        return '/' . $this->bucket . '/' . $encodedKey;
    }

    /**
     * SigV4 wants the query sorted by encoded parameter name, with both name
     * and value percent-encoded.
     *
     * @param array<string, string> $query
     */
    private static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode($name)] = rawurlencode($value);
        }
        ksort($pairs);

        $encoded = [];
        foreach ($pairs as $name => $value) {
            $encoded[] = "{$name}={$value}";
        }

        return implode('&', $encoded);
    }

    /** @param array<string, string> $query */
    private function send(string $method, string $key, ?string $body = null, array $query = []): ResponseInterface
    {
        $host = parse_url($this->origin(), PHP_URL_HOST);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body ?? '');
        $canonicalUri = $this->canonicalUri($key);
        $canonicalQuery = self::canonicalQuery($query);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        ksort($headers);
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= "{$name}:{$value}\n";
        }

        $canonicalRequest = implode("\n", [$method, $canonicalUri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);

        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $now,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->signingKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = self::ALGORITHM . " Credential={$this->accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $uri = $this->origin() . $canonicalUri . ($canonicalQuery === '' ? '' : '?' . $canonicalQuery);
        $request = $this->psr17->createRequest($method, $uri);
        foreach ($headers as $name => $value) {
            // The signing map is assembled from optional pieces, so a value can
            // legitimately be absent; an absent header is simply not sent.
            if (is_string($value)) {
                $request = $request->withHeader($name, $value);
            }
        }
        $request = $request->withHeader('Authorization', $authorization);
        if ($body !== null) {
            $request = $request->withBody($this->psr17->createStream($body));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new S3StorageException("S3 request failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function signingKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function unexpectedStatus(ResponseInterface $response): S3StorageException
    {
        return new S3StorageException(sprintf('S3 request failed with status %d: %s', $response->getStatusCode(), (string) $response->getBody()));
    }
}
