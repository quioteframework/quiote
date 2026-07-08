<?php
namespace Quiote\Security\Auth;

/**
 * The result of {@see OidcClient::buildAuthorizationRequest()}: the URL to
 * redirect the browser to, plus the state/PKCE-verifier/nonce the caller
 * must persist (e.g. via {@see OidcStateStorage}) so the callback leg can
 * verify them.
 * @since      1.0.0
 */
final class OidcAuthorizationRequest
{
	/**
	 * @param      string $authorizationUrl The URL to redirect the browser to.
	 * @param      OidcAuthorizationState $state The state/PKCE-verifier/nonce to persist before redirecting.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly string $authorizationUrl,
		private readonly OidcAuthorizationState $state,
	) {
	}

	/**
	 * @return     string The URL to redirect the browser to.
	 * @since      1.0.0
	 */
	public function getAuthorizationUrl(): string
	{
		return $this->authorizationUrl;
	}

	/**
	 * @return     OidcAuthorizationState The state/PKCE-verifier/nonce to persist before redirecting.
	 * @since      1.0.0
	 */
	public function getState(): OidcAuthorizationState
	{
		return $this->state;
	}
}
