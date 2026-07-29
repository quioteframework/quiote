<?php
namespace Quiote\Security\Auth;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Outbound M2M: fetches an access token via the Client Credentials grant
 * for the app to present to another service. Unrelated to inbound request
 * authentication -- pair with {@see \Quiote\Security\Auth\BearerTokenAuthenticator}
 * (`packages/auth-jwt`) on the *receiving* end.
 * @since      1.0.0
 */
final class ClientCredentialsClient
{
	private readonly SpaceDelimitedScopeProvider $provider;

	/**
	 * The requested scopes, kept because league/oauth2-client only applies a
	 * provider's default `scopes` to the authorization URL — a
	 * `client_credentials` token request has to carry `scope` explicitly or the
	 * configured scopes are silently dropped.
	 * @var array<int, string>
	 */
	private readonly array $scopes;

	/**
	 * @param      string $clientId The OAuth client id.
	 * @param      string $clientSecret The OAuth client secret.
	 * @param      string $tokenEndpoint The authorization server's `/token` endpoint.
	 * @param      array<int, string> $scopes The scopes to request.
	 * @param      ?ClientInterface $httpClient A Guzzle HTTP client override (e.g. for testing); defaults to a real Guzzle client.
	 * @since      1.0.0
	 */
	public function __construct(
		string $clientId,
		string $clientSecret,
		string $tokenEndpoint,
		array $scopes = [],
		?ClientInterface $httpClient = null,
	) {
		$this->provider = new SpaceDelimitedScopeProvider([
			'clientId' => $clientId,
			'clientSecret' => $clientSecret,
			'urlAuthorize' => '',
			'urlAccessToken' => $tokenEndpoint,
			'urlResourceOwnerDetails' => '',
			'scopes' => $scopes,
		], $httpClient !== null ? ['httpClient' => $httpClient] : []);
		$this->scopes = array_values($scopes);
	}

	/**
	 * Builds a client from a provider's discovery document (see
	 * {@see OidcDiscoveryClient}) instead of a hand-copied token-endpoint
	 * URL.
	 * @param      OidcDiscoveryDocument $document The provider's metadata.
	 * @param      string $clientId The OAuth client id.
	 * @param      string $clientSecret The OAuth client secret.
	 * @param      array<int, string> $scopes The scopes to request.
	 * @param      ?ClientInterface $httpClient A Guzzle HTTP client override (e.g. for testing); defaults to a real Guzzle client.
	 * @return     self A client wired to the discovered token endpoint.
	 * @throws     AuthenticationException If the document does not advertise a token endpoint.
	 * @since      1.2.5
	 */
	public static function fromDiscovery(
		OidcDiscoveryDocument $document,
		string $clientId,
		string $clientSecret,
		array $scopes = [],
		?ClientInterface $httpClient = null,
	): self {
		return new self($clientId, $clientSecret, $document->getTokenEndpoint(), $scopes, $httpClient);
	}

	/**
	 * @return     AccessTokenInterface The M2M access token, for the app to present to another service.
	 * @since      1.0.0
	 */
	public function getAccessToken(): AccessTokenInterface
	{
		$options = [];
		if ($this->scopes !== []) {
			// Space-delimited per RFC 6749 §3.3, joined here rather than left to
			// the library — which would send it comma-delimited.
			$options['scope'] = implode(' ', $this->scopes);
		}

		return $this->provider->getAccessToken('client_credentials', $options);
	}
}
