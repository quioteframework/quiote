<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\OidcDiscoveryClient;

/**
 * A PSR-18 client that replays a queued list of responses and records every
 * request it was handed.
 */
class QueueingHttpClient implements ClientInterface
{
	/** @var array<int, RequestInterface> */
	public array $requests = [];

	/** @param array<int, ResponseInterface> $responses */
	public function __construct(private array $responses)
	{
	}

	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$this->requests[] = $request;
		$response = array_shift($this->responses);
		if($response === null) {
			throw new class ('no queued response') extends \RuntimeException implements ClientExceptionInterface {};
		}

		return $response;
	}
}

class FailingDiscoveryHttpClient implements ClientInterface
{
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		throw new class ('dns failure') extends \RuntimeException implements ClientExceptionInterface {};
	}
}

class ArrayCacheItem implements CacheItemInterface
{
	public ?int $ttl = null;

	public function __construct(
		private readonly string $key,
		private mixed $value,
		private bool $hit,
	) {
	}

	public function getKey(): string
	{
		return $this->key;
	}

	public function get(): mixed
	{
		return $this->hit ? $this->value : null;
	}

	public function isHit(): bool
	{
		return $this->hit;
	}

	public function set(mixed $value): static
	{
		$this->value = $value;
		$this->hit = true;
		return $this;
	}

	public function expiresAt(?\DateTimeInterface $expiration): static
	{
		return $this;
	}

	public function expiresAfter(\DateInterval|int|null $time): static
	{
		$this->ttl = is_int($time) ? $time : null;
		return $this;
	}
}

class ArrayCachePool implements CacheItemPoolInterface
{
	/** @var array<string, ArrayCacheItem> */
	public array $items = [];

	public int $saves = 0;

	public function getItem(string $key): CacheItemInterface
	{
		return $this->items[$key] ?? new ArrayCacheItem($key, null, false);
	}

	/**
	 * @param      array<int, string> $keys The keys to fetch.
	 * @return     array<string, CacheItemInterface> The items, keyed by cache key.
	 */
	public function getItems(array $keys = []): iterable
	{
		$items = [];
		foreach($keys as $key) {
			$items[$key] = $this->getItem($key);
		}

		return $items;
	}

	public function hasItem(string $key): bool
	{
		return isset($this->items[$key]);
	}

	public function clear(): bool
	{
		$this->items = [];
		return true;
	}

	public function deleteItem(string $key): bool
	{
		unset($this->items[$key]);
		return true;
	}

	public function deleteItems(array $keys): bool
	{
		foreach($keys as $key) {
			$this->deleteItem($key);
		}

		return true;
	}

	public function save(CacheItemInterface $item): bool
	{
		if(!$item instanceof ArrayCacheItem) {
			return false;
		}

		$this->items[$item->getKey()] = $item;
		$this->saves++;
		return true;
	}

	public function saveDeferred(CacheItemInterface $item): bool
	{
		return $this->save($item);
	}

	public function commit(): bool
	{
		return true;
	}
}

class OidcDiscoveryClientTest extends TestCase
{
	/**
	 * @param      array<string, mixed> $overrides Members to add to or replace in the baseline document.
	 * @return     string The encoded metadata document.
	 */
	private function metadataJson(array $overrides = []): string
	{
		return (string) json_encode($overrides + [
			'issuer' => 'https://idp.example.com',
			'authorization_endpoint' => 'https://idp.example.com/authorize',
			'token_endpoint' => 'https://idp.example.com/token',
			'jwks_uri' => 'https://idp.example.com/keys',
			'code_challenge_methods_supported' => ['S256'],
		]);
	}

	/**
	 * @param      array<int, ResponseInterface> $responses The responses to replay, in order.
	 * @return     QueueingHttpClient The recording client.
	 */
	private function httpClient(array $responses): QueueingHttpClient
	{
		return new QueueingHttpClient($responses);
	}

	private function jsonResponse(string $body, int $status = 200): Response
	{
		return new Response($status, ['Content-Type' => 'application/json'], $body);
	}

	public function testDiscoverFetchesTheWellKnownDocumentAndExposesItsEndpoints(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson())]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory());

		$document = $client->discover('https://idp.example.com');

		$this->assertSame('https://idp.example.com', $document->getIssuer());
		$this->assertSame('https://idp.example.com/authorize', $document->getAuthorizationEndpoint());
		$this->assertSame('https://idp.example.com/token', $document->getTokenEndpoint());
		$this->assertSame('https://idp.example.com/keys', $document->getJwksUri());

		$this->assertCount(1, $httpClient->requests);
		$this->assertSame('GET', $httpClient->requests[0]->getMethod());
		$this->assertSame('https://idp.example.com/.well-known/openid-configuration', (string) $httpClient->requests[0]->getUri());
		$this->assertSame('application/json', $httpClient->requests[0]->getHeaderLine('Accept'));
	}

	public function testDiscoverKeepsTheIssuerPathAndToleratesATrailingSlash(): void
	{
		$issuer = 'https://login.example.com/tenant-1/v2.0';
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson(['issuer' => $issuer]))]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory());

		$document = $client->discover($issuer . '/');

		$this->assertSame($issuer, $document->getIssuer());
		$this->assertSame($issuer . '/.well-known/openid-configuration', (string) $httpClient->requests[0]->getUri());
	}

	public function testDiscoverAcceptsAFullWellKnownUrl(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson())]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory());

		$client->discover('https://idp.example.com/.well-known/openid-configuration');

		$this->assertSame('https://idp.example.com/.well-known/openid-configuration', (string) $httpClient->requests[0]->getUri());
	}

	public function testDiscoverRejectsADocumentForADifferentIssuer(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson(['issuer' => 'https://evil.example.com']))]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory());

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('does not match the requested issuer');
		$client->discover('https://idp.example.com');
	}

	public function testDiscoverRejectsANonHttpsIssuer(): void
	{
		$client = new OidcDiscoveryClient($this->httpClient([]), new Psr17Factory());

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('not HTTPS');
		$client->discover('http://idp.example.com');
	}

	public function testDiscoverAllowsANonHttpsIssuerWhenTheHttpsRequirementIsWaived(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson(['issuer' => 'http://localhost:8080']))]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory(), requireHttps: false);

		$document = $client->discover('http://localhost:8080');

		$this->assertSame('http://localhost:8080', $document->getIssuer());
	}

	public function testDiscoverRejectsAnEmptyIssuer(): void
	{
		$client = new OidcDiscoveryClient($this->httpClient([]), new Psr17Factory());

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('non-empty issuer');
		$client->discover('   ');
	}

	public function testDiscoverThrowsOnANonSuccessStatus(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse('{}', 404)]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory());

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('returned HTTP 404');
		$client->discover('https://idp.example.com');
	}

	public function testDiscoverThrowsWhenTheResponseIsNotAJsonObject(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse('not json at all')]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory());

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('did not return a JSON object');
		$client->discover('https://idp.example.com');
	}

	public function testDiscoverThrowsWhenTheResponseIsAJsonArray(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse('["https://idp.example.com"]')]);
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory());

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('did not return a JSON object');
		$client->discover('https://idp.example.com');
	}

	public function testDiscoverThrowsWhenTheRequestFails(): void
	{
		$client = new OidcDiscoveryClient(new FailingDiscoveryHttpClient(), new Psr17Factory());

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('discovery request failed');
		$client->discover('https://idp.example.com');
	}

	public function testDiscoverCachesTheDocumentAndServesTheSecondCallFromTheCache(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson())]);
		$cache = new ArrayCachePool();
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory(), $cache, cacheTtl: 900);

		$first = $client->discover('https://idp.example.com');
		$second = $client->discover('https://idp.example.com');

		$this->assertSame($first->getMetadata(), $second->getMetadata());
		$this->assertCount(1, $httpClient->requests);
		$this->assertSame(1, $cache->saves);
		$this->assertCount(1, $cache->items);
		$this->assertSame(900, array_values($cache->items)[0]->ttl);
	}

	public function testDiscoverCachesPerIssuer(): void
	{
		$httpClient = $this->httpClient([
			$this->jsonResponse($this->metadataJson()),
			$this->jsonResponse($this->metadataJson(['issuer' => 'https://other.example.com'])),
		]);
		$cache = new ArrayCachePool();
		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory(), $cache);

		$client->discover('https://idp.example.com');
		$other = $client->discover('https://other.example.com');

		$this->assertSame('https://other.example.com', $other->getIssuer());
		$this->assertCount(2, $httpClient->requests);
		$this->assertCount(2, $cache->items);
	}

	public function testDiscoverRefetchesWhenTheCachedEntryIsCorrupt(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson())]);
		$cache = new ArrayCachePool();
		$key = 'quiote_oidc_discovery_' . hash('sha256', 'https://idp.example.com');
		$cache->save((new ArrayCacheItem($key, 'truncated{', false))->set('truncated{'));

		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory(), $cache);
		$document = $client->discover('https://idp.example.com');

		$this->assertSame('https://idp.example.com/token', $document->getTokenEndpoint());
		$this->assertCount(1, $httpClient->requests);
		$this->assertSame($this->metadataJson(), $cache->items[$key]->get());
	}

	public function testDiscoverIgnoresANonStringCacheEntry(): void
	{
		$httpClient = $this->httpClient([$this->jsonResponse($this->metadataJson())]);
		$cache = new ArrayCachePool();
		$key = 'quiote_oidc_discovery_' . hash('sha256', 'https://idp.example.com');
		$cache->save((new ArrayCacheItem($key, null, false))->set(['issuer' => 'https://idp.example.com']));

		$client = new OidcDiscoveryClient($httpClient, new Psr17Factory(), $cache);
		$document = $client->discover('https://idp.example.com');

		$this->assertSame('https://idp.example.com/keys', $document->getJwksUri());
		$this->assertCount(1, $httpClient->requests);
	}
}
