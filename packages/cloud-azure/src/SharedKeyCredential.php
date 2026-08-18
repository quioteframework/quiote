<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Shared Key authentication: signs every request with an HMAC-SHA256 over the storage account
 * key, the way {@see AzureBlobClient} always used to before {@see AzureCredential} existed.
 *
 * @see https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key
 */
final class SharedKeyCredential implements AzureCredential
{
    public function __construct(private readonly string $accountKey)
    {
    }

    /** @inheritDoc */
    #[\Override]
    public function authorizationHeader(string $accountName, string $method, string $path, array $query, array $headers): string
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
            $this->canonicalizedHeaders($headers) . $this->canonicalizedResource($accountName, $path, $query),
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $this->decodedAccountKey(), true));

        return "SharedKey {$accountName}:{$signature}";
    }

    /**
     * The storage account key is base64 in every Azure surface that hands it out, but it arrives
     * here as untrusted configuration; hash_hmac() needs a string, not base64_decode()'s false.
     */
    private function decodedAccountKey(): string
    {
        $decoded = base64_decode($this->accountKey, true);
        if ($decoded === false) {
            throw new AzureStorageException('The Azure storage account key is not valid base64.');
        }

        return $decoded;
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
    private function canonicalizedResource(string $accountName, string $path, array $query): string
    {
        $canonical = "/{$accountName}{$path}";

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
}
