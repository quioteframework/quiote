<?php
namespace Quiote\Security\Auth;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A ~30-line RFC 7662 (OAuth 2.0 Token Introspection) POST helper --
 * `league/oauth2-client` has none. Used only on revocation-sensitive
 * paths; the default resource-server validation is local JWKS
 * verification via `packages/auth-jwt`.
 * @since      1.0.0
 */
final class IntrospectionClient
{
	/**
	 * @param      ClientInterface $httpClient A PSR-18 HTTP client.
	 * @param      RequestFactoryInterface $requestFactory A PSR-17 request factory.
	 * @param      StreamFactoryInterface $streamFactory A PSR-17 stream factory, for the POST body.
	 * @param      string $introspectionEndpoint The authorization server's RFC 7662 introspection endpoint.
	 * @param      string $clientId The OAuth client id, sent via HTTP Basic per RFC 7662 §2.1.
	 * @param      string $clientSecret The OAuth client secret, sent via HTTP Basic per RFC 7662 §2.1.
	 * @since      1.0.0
	 */
	public function __construct(
		private readonly ClientInterface $httpClient,
		private readonly RequestFactoryInterface $requestFactory,
		private readonly StreamFactoryInterface $streamFactory,
		private readonly string $introspectionEndpoint,
		private readonly string $clientId,
		private readonly string $clientSecret,
	) {
	}

	/**
	 * @param      string $token The token to introspect.
	 * @return     array<string, mixed> The introspection response.
	 * @throws     AuthenticationException If the request fails, the response is malformed, or the token is not active.
	 * @since      1.0.0
	 */
	public function introspect(string $token): array
	{
		$body = http_build_query(['token' => $token]);
		$request = $this->requestFactory->createRequest('POST', $this->introspectionEndpoint)
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withHeader('Authorization', 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret))
			->withBody($this->streamFactory->createStream($body));

		try {
			$response = $this->httpClient->sendRequest($request);
		} catch(\Throwable $e) {
			throw new AuthenticationException('Introspection request failed: ' . $e->getMessage(), previous: $e);
		}

		$decoded = json_decode((string) $response->getBody(), true);
		if(!is_array($decoded)) {
			throw new AuthenticationException('Introspection endpoint returned a malformed response.');
		}

		/** @var array<string, mixed> $result */
		$result = [];
		foreach($decoded as $key => $value) {
			$result[(string) $key] = $value;
		}

		if(($result['active'] ?? false) !== true) {
			throw new AuthenticationException('Token is not active per introspection.');
		}

		return $result;
	}
}
