<?php
namespace Quiote\Security\Auth;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Throwable;

/**
 * Wraps `league/oauth2-client`'s `GenericProvider` for the OIDC
 * Authorization Code flow. PKCE (S256) is hardcoded, not an
 * app-configurable option, since OAuth 2.1 mandates it for the
 * Authorization Code grant. The `nonce` authorization-request parameter
 * and its later ID-token verification are entirely our own
 * responsibility -- `league/oauth2-client` is OAuth2-only and has no
 * OIDC/nonce concept.
 * @since      1.0.0
 */
final class OidcClient
{
	private readonly GenericProvider $provider;

	/**
	 * @param      string $clientId The OAuth client id.
	 * @param      string $clientSecret The OAuth client secret.
	 * @param      string $redirectUri This app's callback URL, registered with the authorization server.
	 * @param      string $authorizationEndpoint The authorization server's `/authorize` endpoint.
	 * @param      string $tokenEndpoint The authorization server's `/token` endpoint.
	 * @param      array<int, string> $scopes The scopes to request.
	 * @param      ?ClientInterface $httpClient A Guzzle HTTP client override (e.g. for testing); defaults to a real Guzzle client.
	 * @since      1.0.0
	 */
	public function __construct(
		string $clientId,
		string $clientSecret,
		string $redirectUri,
		string $authorizationEndpoint,
		string $tokenEndpoint,
		array $scopes = ['openid'],
		?ClientInterface $httpClient = null,
	) {
		$this->provider = new GenericProvider([
			'clientId' => $clientId,
			'clientSecret' => $clientSecret,
			'redirectUri' => $redirectUri,
			'urlAuthorize' => $authorizationEndpoint,
			'urlAccessToken' => $tokenEndpoint,
			'urlResourceOwnerDetails' => '',
			'scopes' => $scopes,
			'pkceMethod' => AbstractProvider::PKCE_METHOD_S256,
		], $httpClient !== null ? ['httpClient' => $httpClient] : []);
	}

	/**
	 * Generates state/PKCE-verifier/nonce and builds the authorization
	 * redirect URL. The caller persists the returned state (e.g. via
	 * {@see OidcStateStorage::store()}) before redirecting the browser.
	 * @return     OidcAuthorizationRequest The redirect URL plus the state to persist.
	 * @since      1.0.0
	 */
	public function buildAuthorizationRequest(): OidcAuthorizationRequest
	{
		$nonce = bin2hex(random_bytes(16));
		$url = $this->provider->getAuthorizationUrl(['nonce' => $nonce]);
		$state = $this->provider->getState();
		$pkceVerifier = $this->provider->getPkceCode();

		return new OidcAuthorizationRequest($url, new OidcAuthorizationState($state, $pkceVerifier ?? '', $nonce));
	}

	/**
	 * Exchanges an authorization code for tokens, using the PKCE verifier
	 * persisted from the matching {@see buildAuthorizationRequest()} call.
	 * @param      string $code The authorization code received on the callback.
	 * @param      string $pkceVerifier The PKCE `code_verifier` from the matching {@see OidcAuthorizationState}.
	 * @return     AccessTokenInterface The token response, including the ID token (see `getValues()['id_token']`).
	 * @throws     AuthenticationException If the token endpoint rejects the exchange.
	 * @since      1.0.0
	 */
	public function exchangeCode(string $code, string $pkceVerifier): AccessTokenInterface
	{
		$this->provider->setPkceCode($pkceVerifier);
		try {
			return $this->provider->getAccessToken('authorization_code', ['code' => $code]);
		} catch(Throwable $e) {
			throw new AuthenticationException('OIDC token exchange failed: ' . $e->getMessage(), previous: $e);
		}
	}
}
