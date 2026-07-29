<?php

namespace Quiote\Security\Csrf;

use Quiote\Context;
use Quiote\Session\SessionBagInterface;
use Symfony\Component\Security\Core\Exception\TokenNotFoundException;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

/**
 * Symfony CSRF TokenStorage backed by Quiote's session.
 * Lets symfony/security-csrf persist its per-session tokens through whichever
 * session backend the context is configured with, instead of the component's
 * own NativeSessionTokenStorage, so CSRF state lives in the same session as the
 * rest of the application -- notably the same one the User hierarchy uses. */
final readonly class SessionTokenStorage implements TokenStorageInterface
{
    /** Namespace prefix for CSRF token keys in the session. */
    private const string PREFIX = 'org.quiote.csrf.';

    public function __construct(private Context $context)
    {
    }

    private function bag(): SessionBagInterface
    {
        return $this->context->getSessionBag();
    }

    public function getToken(string $tokenId): string
    {
        $value = $this->bag()->get(self::PREFIX . $tokenId);
        if (!is_string($value) || $value === '') {
            throw new TokenNotFoundException('The CSRF token with ID "' . $tokenId . '" does not exist.');
        }
        return $value;
    }

    public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
    {
        $this->bag()->set(self::PREFIX . $tokenId, $token);
    }

    public function removeToken(string $tokenId): ?string
    {
        $key = self::PREFIX . $tokenId;
        $bag = $this->bag();
        $value = $bag->get($key);
        $bag->remove($key);

        return is_string($value) ? $value : null;
    }

    public function hasToken(string $tokenId): bool
    {
        return is_string($this->bag()->get(self::PREFIX . $tokenId));
    }
}
