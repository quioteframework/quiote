<?php
namespace Quiote\Security\Auth;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/**
 * Fetches an OpenID provider's metadata from
 * `{issuer}/.well-known/openid-configuration` (OpenID Connect Discovery
 * 1.0 §4) so an app can wire {@see OidcClient}, {@see ClientCredentialsClient},
 * {@see IntrospectionClient} and `auth-jwt`'s JWKS key set from one issuer
 * URL instead of five hand-copied endpoint strings that silently rot when
 * the provider moves them.
 *
 * PSR-18 + PSR-17 rather than Guzzle, matching {@see IntrospectionClient};
 * discovery is a plain GET and does not need `league/oauth2-client`. An
 * optional PSR-6 pool caches the document -- discovery is a synchronous
 * network hop, so an uncached fetch on every worker boot (or every request
 * under PHP-FPM) adds the provider's latency to the app's own. The pool is
 * PSR-6 to match the pool `firebase/php-jwt`'s `CachedKeySet` already
 * needs for the JWKS in the same auth stack.
 * @since      1.2.5
 */
final class OidcDiscoveryClient
{
	/**
	 * The path appended to the issuer identifier per OpenID Connect
	 * Discovery 1.0 §4 -- note the issuer's own path (if any) is retained
	 * and this is appended after it, unlike RFC 8414's insert-before-path
	 * form.
	 */
	private const WELL_KNOWN_PATH = '/.well-known/openid-configuration';

	/**
	 * @param      ClientInterface $httpClient A PSR-18 HTTP client.
	 * @param      RequestFactoryInterface $requestFactory A PSR-17 request factory.
	 * @param      ?CacheItemPoolInterface $cache A PSR-6 pool to cache fetched documents in, or null to fetch on every call.
	 * @param      int $cacheTtl How long (seconds) a cached document stays fresh; providers change endpoints rarely, so hours are reasonable.
	 * @param      bool $requireHttps Whether to reject non-HTTPS issuers, as Discovery §4 requires. Only turn this off for a local test provider.
	 * @since      1.2.5
	 */
	public function __construct(
		private readonly ClientInterface $httpClient,
		private readonly RequestFactoryInterface $requestFactory,
		private readonly ?CacheItemPoolInterface $cache = null,
		private readonly int $cacheTtl = 3600,
		private readonly bool $requireHttps = true,
	) {
	}

	/**
	 * @param      string $issuer The provider's issuer identifier, e.g. `https://login.microsoftonline.com/{tenant}/v2.0`. A full `.../.well-known/openid-configuration` URL is also accepted and its issuer part used.
	 * @return     OidcDiscoveryDocument The provider's metadata, issuer-verified per Discovery §4.3.
	 * @throws     AuthenticationException If the issuer is unusable, the request fails, the response is not a 2xx JSON object, or its `issuer` does not match.
	 * @since      1.2.5
	 */
	public function discover(string $issuer): OidcDiscoveryDocument
	{
		$issuer = $this->normalizeIssuer($issuer);

		$cacheKey = 'quiote_oidc_discovery_' . hash('sha256', $issuer);
		if($this->cache !== null) {
			$item = $this->cache->getItem($cacheKey);
			$cached = $item->isHit() ? $item->get() : null;
			if(is_string($cached)) {
				// A cached document was valid when stored, so a decode failure here
				// means a corrupt entry rather than a bad provider: fall through to a
				// fresh fetch instead of failing the request.
				$decoded = $this->decode($cached);
				if($decoded !== null) {
					return OidcDiscoveryDocument::fromArray($decoded, $issuer);
				}
			}
		}

		$body = $this->fetch($issuer . self::WELL_KNOWN_PATH);
		$metadata = $this->decode($body);
		if($metadata === null) {
			throw new AuthenticationException('OIDC discovery endpoint did not return a JSON object.');
		}

		$document = OidcDiscoveryDocument::fromArray($metadata, $issuer);

		if($this->cache !== null) {
			$this->cache->save($this->cache->getItem($cacheKey)->set($body)->expiresAfter($this->cacheTtl));
		}

		return $document;
	}

	/**
	 * @param      string $issuer The issuer identifier as configured.
	 * @return     string The issuer with any trailing slash and any trailing well-known path stripped.
	 * @throws     AuthenticationException If the issuer is empty, or is not HTTPS while $requireHttps is on.
	 * @since      1.2.5
	 */
	private function normalizeIssuer(string $issuer): string
	{
		$issuer = trim($issuer);
		if(str_ends_with($issuer, self::WELL_KNOWN_PATH)) {
			$issuer = substr($issuer, 0, -strlen(self::WELL_KNOWN_PATH));
		}
		$issuer = rtrim($issuer, '/');

		if($issuer === '') {
			throw new AuthenticationException('OIDC discovery requires a non-empty issuer URL.');
		}

		if($this->requireHttps && !str_starts_with(strtolower($issuer), 'https://')) {
			throw new AuthenticationException(sprintf('OIDC issuer "%s" is not HTTPS; discovery over plaintext is refused.', $issuer));
		}

		return $issuer;
	}

	/**
	 * @param      string $url The discovery-document URL.
	 * @return     string The response body.
	 * @throws     AuthenticationException If the request fails or the response status is not 2xx.
	 * @since      1.2.5
	 */
	private function fetch(string $url): string
	{
		$request = $this->requestFactory->createRequest('GET', $url)
			->withHeader('Accept', 'application/json');

		try {
			$response = $this->httpClient->sendRequest($request);
		} catch(Throwable $e) {
			throw new AuthenticationException('OIDC discovery request failed: ' . $e->getMessage(), previous: $e);
		}

		$status = $response->getStatusCode();
		if($status < 200 || $status > 299) {
			throw new AuthenticationException(sprintf('OIDC discovery endpoint %s returned HTTP %d.', $url, $status));
		}

		return (string) $response->getBody();
	}

	/**
	 * @param      string $body The raw JSON body.
	 * @return     ?array<string, mixed> The decoded metadata, or null if the body is not a JSON object.
	 * @since      1.2.5
	 */
	private function decode(string $body): ?array
	{
		$decoded = json_decode($body, true);
		if(!is_array($decoded) || array_is_list($decoded)) {
			return null;
		}

		/** @var array<string, mixed> $metadata */
		$metadata = [];
		foreach($decoded as $key => $value) {
			$metadata[(string) $key] = $value;
		}

		return $metadata;
	}
}
