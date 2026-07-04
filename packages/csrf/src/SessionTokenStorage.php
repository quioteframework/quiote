<?php

namespace Quiote\Security\Csrf;

use Quiote\Context;
use Symfony\Component\Security\Core\Exception\TokenNotFoundException;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

/**
 * Symfony CSRF TokenStorage backed by Quiote's session storage.
 * Lets symfony/security-csrf persist its per-session tokens through whatever
 * Storage the context provides (native session, PDO, etc.) instead of the
 * component's own NativeSessionTokenStorage, so CSRF state lives in the same
 * session as the rest of the application. */
final readonly class SessionTokenStorage implements TokenStorageInterface
{
    /** Namespace prefix for CSRF token keys in the session. */
    private const string PREFIX = 'org.quiote.csrf.';

    public function __construct(private Context $context)
    {
    }

    private function storage(): object
    {
        return $this->context->getStorage();
    }

    public function getToken(string $tokenId): string
    {
        $value = $this->storage()->retrieve(self::PREFIX . $tokenId);
        if (!is_string($value) || $value === '') {
            throw new TokenNotFoundException('The CSRF token with ID "' . $tokenId . '" does not exist.');
        }
        return $value;
    }

    public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
    {
        $this->storage()->store(self::PREFIX . $tokenId, $token);
    }

    public function removeToken(string $tokenId): ?string
    {
        $key = self::PREFIX . $tokenId;
        $value = $this->storage()->retrieve($key);
        try {
            $this->storage()->remove($key);
        } catch (\Throwable) {
        }
        return is_string($value) ? $value : null;
    }

    public function hasToken(string $tokenId): bool
    {
        return is_string($this->storage()->retrieve(self::PREFIX . $tokenId));
    }
}
