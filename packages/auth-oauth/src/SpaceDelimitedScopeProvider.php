<?php
namespace Quiote\Security\Auth;

use League\OAuth2\Client\Provider\GenericProvider;

/**
 * `league/oauth2-client`'s `AbstractProvider::getScopeSeparator()` returns a
 * comma and `GenericProvider` does not override it, so a multi-scope
 * authorization request comes out as `scope=openid%2Cprofile%2Cemail`.
 * RFC 6749 §3.3 defines `scope` as a space-delimited list, and real
 * authorization servers (Google, Microsoft Entra ID, Okta) either reject the
 * comma form or parse it as a single unknown scope. The failure surfaces at
 * the provider's authorize endpoint, after the redirect has left this app —
 * about the worst place to debug it — so the separator is corrected here
 * rather than left to each caller to pre-join.
 * @since      3.0.2
 */
final class SpaceDelimitedScopeProvider extends GenericProvider
{
	/**
	 * @return     string A single space, per RFC 6749 §3.3.
	 * @since      3.0.2
	 */
	protected function getScopeSeparator(): string
	{
		return ' ';
	}
}
