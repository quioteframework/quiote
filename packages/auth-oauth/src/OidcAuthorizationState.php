<?php
namespace Quiote\Security\Auth;

/**
 * The per-attempt secrets an OIDC auth-code + PKCE flow must round-trip
 * through the user's session between the authorization redirect and the
 * callback: the CSRF-style `state`, the PKCE `code_verifier`, and the
 * OIDC `nonce` (replay/injection protection for the ID token).
 * @since      1.0.0
 */
final class OidcAuthorizationState
{
	/**
	 * @param      string $state The CSRF-style `state` value sent to and echoed back by the authorization server.
	 * @param      string $pkceVerifier The PKCE `code_verifier` (S256 challenge was derived from this).
	 * @param      string $nonce The OIDC `nonce` sent in the authorization request, expected back in the ID token.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly string $state,
		private readonly string $pkceVerifier,
		private readonly string $nonce,
	) {
	}

	/**
	 * @return     string The `state` value.
	 * @since      1.0.0
	 */
	public function getState(): string
	{
		return $this->state;
	}

	/**
	 * @return     string The PKCE `code_verifier`.
	 * @since      1.0.0
	 */
	public function getPkceVerifier(): string
	{
		return $this->pkceVerifier;
	}

	/**
	 * @return     string The OIDC `nonce`.
	 * @since      1.0.0
	 */
	public function getNonce(): string
	{
		return $this->nonce;
	}
}
