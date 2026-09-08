<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * The parse-and-validate step shared by every {@see AzureTokenProvider} that gets its token back
 * as a JSON body: decode it, then confirm the token field is actually there before trusting it.
 */
trait DecodesBearerTokenJson
{
    /**
     * @return array{token: string, expiresIn: int}
     */
    private static function decodeBearerToken(string $json, string $tokenField, string $sourceDescription, string $missingTokenAdvice = ''): array
    {
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AzureStorageException("{$sourceDescription} returned a token response that was not valid JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($payload) || !isset($payload[$tokenField]) || !is_string($payload[$tokenField])) {
            throw new AzureStorageException("{$sourceDescription} token response had no \"{$tokenField}\"{$missingTokenAdvice}.");
        }

        $expiresIn = is_numeric($payload['expires_in'] ?? null) ? (int) $payload['expires_in'] : 0;

        return ['token' => $payload[$tokenField], 'expiresIn' => $expiresIn];
    }
}
