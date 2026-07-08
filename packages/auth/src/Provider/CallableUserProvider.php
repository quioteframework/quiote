<?php
namespace Quiote\Security\Auth\Provider;

use Closure;
use Quiote\Security\Auth\TokenClaims;
use Quiote\Security\Auth\UserIdentity;
use Quiote\Security\Auth\UserProviderInterface;

/**
 * Delegates identity/claim resolution to app-supplied callables, for
 * lookups that don't fit `InMemoryUserProvider`'s static list or
 * `PdoUserProvider`'s single-table convention (e.g. joining across
 * services, calling a legacy API).
 * @since      1.0.0
 */
final class CallableUserProvider implements UserProviderInterface
{
	private readonly Closure $byIdentifier;

	private readonly ?Closure $byToken;

	/**
	 * @param      callable(string): ?UserIdentity $byIdentifier Resolves an identifier (e.g. email/username) to an identity.
	 * @param      (callable(TokenClaims): ?UserIdentity)|null $byToken Resolves validated token claims to an identity, if token-based auth is used.
	 * @since      1.0.0
	 */
	public function __construct(callable $byIdentifier, ?callable $byToken = null)
	{
		$this->byIdentifier = Closure::fromCallable($byIdentifier);
		$this->byToken = $byToken !== null ? Closure::fromCallable($byToken) : null;
	}

	/**
	 * @param      string $identifier E.g. an email or username.
	 * @return     ?UserIdentity Whatever the $byIdentifier callable returns.
	 * @since      1.0.0
	 */
	public function loadByIdentifier(string $identifier): ?UserIdentity
	{
		return ($this->byIdentifier)($identifier);
	}

	/**
	 * @param      TokenClaims $claims The validated token claims.
	 * @return     ?UserIdentity Whatever the $byToken callable returns, or null if none was supplied.
	 * @since      1.0.0
	 */
	public function loadByToken(TokenClaims $claims): ?UserIdentity
	{
		return $this->byToken !== null ? ($this->byToken)($claims) : null;
	}
}
