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
 * every AWS service) for the three operations a session backend needs: get,
 * put, delete a single object. Path-style requests, so `endpoint` also works
 * against any S3-compatible service (MinIO, etc). The bucket is assumed to
 * already exist — bucket lifecycle is normally managed outside the app
 * (IaC), unlike Azure's implicit per-account containers.
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

    private function origin(): string
    {
        return $this->endpoint ?? "https://s3.{$this->region}.amazonaws.com";
    }

    private function canonicalUri(string $key): string
    {
        $encodedKey = implode('/', array_map(rawurlencode(...), explode('/', $key)));

        return '/' . $this->bucket . '/' . $encodedKey;
    }

    private function send(string $method, string $key, ?string $body = null): ResponseInterface
    {
        $host = parse_url($this->origin(), PHP_URL_HOST);
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body ?? '');
        $canonicalUri = $this->canonicalUri($key);

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

        $canonicalRequest = implode("\n", [$method, $canonicalUri, '', $canonicalHeaders, $signedHeaders, $payloadHash]);

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

        $request = $this->psr17->createRequest($method, $this->origin() . $canonicalUri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
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
