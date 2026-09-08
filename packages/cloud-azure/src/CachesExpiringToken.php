<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * The cache-until-near-expiry behaviour shared by every {@see AzureTokenProvider}: hold the last
 * token and serve it back until its TTL runs out, then let the caller fetch a fresh one.
 */
trait CachesExpiringToken
{
    private ?string $cachedToken = null;
    private int $tokenExpiresAt = 0;

    private function cachedTokenOrNull(): ?string
    {
        return $this->cachedToken !== null && time() < $this->tokenExpiresAt ? $this->cachedToken : null;
    }

    private function cacheToken(string $token, int $ttlSeconds): string
    {
        $this->cachedToken = $token;
        $this->tokenExpiresAt = time() + max(0, $ttlSeconds);

        return $token;
    }
}
