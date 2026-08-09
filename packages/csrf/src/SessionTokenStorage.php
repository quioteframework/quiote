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
        return $this->context->getContainer()->get(\Quiote\Session\SessionBagInterface::class);
    }

    /**
     * Returns the stored token for the given id.
     *
     * The value is read from the session bag under this class's namespace
     * prefix. A missing entry, and equally one that is empty or not a string,
     * counts as no token at all and raises `TokenNotFoundException`, as the
     * Symfony storage contract requires.
     *
     * @throws TokenNotFoundException if no usable token is stored for the id.
     */
    public function getToken(string $tokenId): string
    {
        $value = $this->bag()->get(self::PREFIX . $tokenId);
        if (!is_string($value) || $value === '') {
            throw new TokenNotFoundException('The CSRF token with ID "' . $tokenId . '" does not exist.');
        }
        return $value;
    }

    /**
     * Stores the token for the given id in the session bag, replacing any
     * value already held under that id.
     */
    public function setToken(string $tokenId, #[\SensitiveParameter] string $token): void
    {
        $this->bag()->set(self::PREFIX . $tokenId, $token);
    }

    /**
     * Removes the token for the given id and returns the value that was held.
     *
     * Returns null when nothing was stored, or when what was stored was not a
     * string; the session entry is removed either way.
     */
    public function removeToken(string $tokenId): ?string
    {
        $key = self::PREFIX . $tokenId;
        $bag = $this->bag();
        $value = $bag->get($key);
        $bag->remove($key);

        return is_string($value) ? $value : null;
    }

    /** Whether a token is currently stored in the session for the given id. */
    public function hasToken(string $tokenId): bool
    {
        return is_string($this->bag()->get(self::PREFIX . $tokenId));
    }
}
