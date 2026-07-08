<?php
namespace Quiote\Security\Auth;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\GenericProvider;
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
	private readonly GenericProvider $provider;

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
		$this->provider = new GenericProvider([
			'clientId' => $clientId,
			'clientSecret' => $clientSecret,
			'urlAuthorize' => '',
			'urlAccessToken' => $tokenEndpoint,
			'urlResourceOwnerDetails' => '',
			'scopes' => $scopes,
		], $httpClient !== null ? ['httpClient' => $httpClient] : []);
	}

	/**
	 * @return     AccessTokenInterface The M2M access token, for the app to present to another service.
	 * @since      1.0.0
	 */
	public function getAccessToken(): AccessTokenInterface
	{
		return $this->provider->getAccessToken('client_credentials');
	}
}
