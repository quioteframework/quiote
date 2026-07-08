<?php
namespace Quiote\Security\Auth;

/**
 * The resolved outcome of a successful {@see AuthenticatorInterface::authenticate()}
 * call: the identity plus the credentials/roles to grant, and whether the
 * identity is stateless (re-derived from the credential every request
 * rather than read back from the session). Consumed by
 * `Quiote\Security\Auth\AuthenticationManager` (`packages/auth`) to
 * populate a `SecurityUser`/`RbacSecurityUser`.
 * @since      1.0.0
 */
final class Passport
{
	/**
	 * @param      UserIdentity $identity The resolved identity.
	 * @param      array<int, string> $credentials Roles/permissions to grant on the SecurityUser.
	 * @param      bool $stateless True if the identity is re-derived from the credential every request.
	 * @param      ?TokenClaims $claims The token this passport was derived from, if any (see getClaims()).
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly UserIdentity $identity,
		private readonly array $credentials = [],
		private readonly bool $stateless = false,
		private readonly ?TokenClaims $claims = null,
	) {
	}

	/**
	 * @return     UserIdentity The identity resolved by the authenticator.
	 * @since      1.0.0
	 */
	public function getIdentity(): UserIdentity
	{
		return $this->identity;
	}

	/**
	 * @return     array<int, string> Roles/permissions to grant on the SecurityUser.
	 * @since      1.0.0
	 */
	public function getCredentials(): array
	{
		return $this->credentials;
	}

	/**
	 * @return     bool True if the identity is re-derived from the credential every request, otherwise false.
	 * @since      1.0.0
	 */
	public function isStateless(): bool
	{
		return $this->stateless;
	}

	/**
	 * The token claims this passport was derived from, if the authenticator
	 * that produced it was token-based (bearer/JWT/OIDC).
	 * @return     ?TokenClaims The token claims, or null for a non-token authenticator (form login, HTTP Basic).
	 * @since      1.0.0
	 */
	public function getClaims(): ?TokenClaims
	{
		return $this->claims;
	}
}
