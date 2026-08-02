<?php

use Firebase\JWT\JWT;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Config\Config;
use Quiote\Mcp\McpCatalog;
use Quiote\Mcp\McpConfig;
use Quiote\Mcp\McpServer;
use Quiote\Testing\PhpUnitTestCase;

/**
 * A PSR-18 client that serves canned OIDC discovery + JWKS documents for a
 * fixed issuer, so `OidcDiscovery`/`JwksProvider` (both used inside
 * McpServer::buildHttpMiddleware()) never make a real network call in tests.
 */
final class McpOAuthFakeHttpClient implements ClientInterface
{
    /** @param array<string, mixed> $discoveryDocument */
    public function __construct(
        private readonly array $discoveryDocument,
        private readonly string $jwksUri,
        /** @var array<string, mixed> */
        private readonly array $jwksDocument,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();

        if ($url === $this->jwksUri) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($this->jwksDocument, JSON_THROW_ON_ERROR));
        }

        if (str_contains($url, '/.well-known/oauth-authorization-server') || str_contains($url, '/.well-known/openid-configuration')) {
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($this->discoveryDocument, JSON_THROW_ON_ERROR));
        }

        return new Response(404);
    }
}

/**
 * OAuth2 resource-server enforcement on the MCP HTTP endpoint
 * (`mcp.auth = 'oauth2'`): `McpServer::buildHttpMiddleware()` composes the
 * SDK's own `OidcDiscovery`/`JwksProvider`/`JwtTokenValidator`/
 * `AuthorizationMiddleware`/`ProtectedResourceMetadataMiddleware` and hands
 * them to `StreamableHttpTransport`. A fake PSR-18 client
 * ({@see McpOAuthFakeHttpClient}) stands in for the real issuer, and a
 * throwaway RSA keypair signs fixture tokens.
 */
final class McpServerOAuthIntegrationTest extends PhpUnitTestCase
{
    private const ISSUER = 'https://auth.example.test';
    private const AUDIENCE = 'mcp-api';
    private const JWKS_URI = 'https://auth.example.test/jwks';
    private const KID = 'test-kid';

    /** @var array{private: string, jwk: array<string, mixed>}|null */
    private static ?array $keyPair = null;

    #[Before]
    #[After]
    public function resetState(): void
    {
        McpCatalog::reset();
        foreach (['mcp.enabled', 'mcp.path', 'mcp.auth', 'mcp.oauth.issuer', 'mcp.oauth.audience', 'mcp.oauth.jwks_uri', 'mcp.oauth.scopes_supported'] as $key) {
            Config::remove($key);
        }
    }

    private function configureOauth2(): void
    {
        Config::set('mcp.enabled', true, true);
        Config::set('mcp.path', '/mcp', true);
        Config::set('mcp.auth', 'oauth2', true);
        Config::set('mcp.oauth.issuer', self::ISSUER, true);
        Config::set('mcp.oauth.audience', self::AUDIENCE, true);
    }

    /** @return array{private: string, jwk: array<string, mixed>} */
    private function keyPair(): array
    {
        if (self::$keyPair !== null) {
            return self::$keyPair;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource);
        $exported = openssl_pkey_export($resource, $privateKey);
        self::assertTrue($exported);
        self::assertIsString($privateKey);

        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);
        self::assertIsArray($details['rsa'] ?? null);
        self::assertIsString($details['rsa']['n'] ?? null);
        self::assertIsString($details['rsa']['e'] ?? null);

        $jwk = [
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];

        return self::$keyPair = ['private' => $privateKey, 'jwk' => $jwk];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $claimOverrides */
    private function signToken(array $claimOverrides = []): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'user-123',
            'scope' => 'mcp:read mcp:write',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $claimOverrides);

        return JWT::encode($claims, $this->keyPair()['private'], 'RS256', self::KID);
    }

    private function fakeHttpClient(): McpOAuthFakeHttpClient
    {
        return new McpOAuthFakeHttpClient(
            discoveryDocument: [
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER . '/authorize',
                'token_endpoint' => self::ISSUER . '/token',
                'jwks_uri' => self::JWKS_URI,
                'code_challenge_methods_supported' => ['S256'],
            ],
            jwksUri: self::JWKS_URI,
            jwksDocument: ['keys' => [$this->keyPair()['jwk']]],
        );
    }

    private function server(): McpServer
    {
        return new McpServer(new \Quiote\DI\Container(), 'mcp-oauth-test', $this->fakeHttpClient());
    }

    /** @param array<string, mixed> $body */
    private function jsonRpcRequest(array $body, ?string $bearerToken = null): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $stream = $factory->createStream(json_encode($body, JSON_THROW_ON_ERROR));
        $request = $factory->createServerRequest('POST', 'http://localhost/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        return $bearerToken !== null ? $request->withHeader('Authorization', 'Bearer ' . $bearerToken) : $request;
    }

    public function testValidTokenAllowsToolsListToSucceed(): void
    {
        $this->configureOauth2();
        $config = McpConfig::fromConfig();

        $response = $this->server()->handleHttp($config, $this->jsonRpcRequest([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
        ], $this->signToken()));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMissingAuthorizationHeaderIsRejectedWith401AndAChallengeHeader(): void
    {
        $this->configureOauth2();
        $config = McpConfig::fromConfig();

        $response = $this->server()->handleHttp($config, $this->jsonRpcRequest([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
        ]));

        $this->assertSame(401, $response->getStatusCode());
        $challenge = $response->getHeaderLine('WWW-Authenticate');
        $this->assertStringContainsString('Bearer', $challenge);
        $this->assertStringContainsString('resource_metadata=', $challenge);
    }

    public function testTokenWithWrongAudienceIsRejectedWith401(): void
    {
        $this->configureOauth2();
        $config = McpConfig::fromConfig();

        $response = $this->server()->handleHttp($config, $this->jsonRpcRequest([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
        ], $this->signToken(['aud' => 'someone-else'])));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('invalid_token', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testExpiredTokenIsRejectedWith401(): void
    {
        $this->configureOauth2();
        $config = McpConfig::fromConfig();

        $response = $this->server()->handleHttp($config, $this->jsonRpcRequest([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
        ], $this->signToken(['iat' => time() - 7200, 'exp' => time() - 3600])));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('invalid_token', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testDiscoveryEndpointServesProtectedResourceMetadata(): void
    {
        $this->configureOauth2();
        $config = McpConfig::fromConfig();

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'http://localhost/.well-known/oauth-protected-resource');

        $response = $this->server()->handleHttp($config, $request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $this->assertSame([self::ISSUER], $payload['authorization_servers']);
    }

    /**
     * A non-matching path must still fall through even when oauth2 is on --
     * deliberately *not* asserting the matching-path case through the real
     * middleware here: {@see McpEndpointMiddleware} builds its own
     * `McpServer` with no injectable HTTP client, so a matching request
     * would trigger a real (network-dependent, hence potentially flaky)
     * OIDC discovery attempt. That composition is already proven, without
     * any network dependency, by {@see testDiscoveryEndpointServesProtectedResourceMetadata}
     * via the fake client wired directly into `McpServer::handleHttp()`.
     */
    public function testEndpointMiddlewareStillFallsThroughForANonMatchingPathWhenOauth2IsEnabled(): void
    {
        $this->configureOauth2();

        $middleware = new \Quiote\Mcp\Middleware\McpEndpointMiddleware('mcp-oauth-test');
        $next = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public bool $called = false;

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new Response(200);
            }
        };

        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'http://localhost/some-unrelated-path');

        $middleware->process($request, $next);

        $this->assertTrue($next->called);
    }
}
