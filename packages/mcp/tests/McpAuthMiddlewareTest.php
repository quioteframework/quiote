<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Mcp\Auth\McpAuthenticatorInterface;
use Quiote\Mcp\Auth\StaticTokenAuthenticator;
use Quiote\Mcp\Middleware\McpAuthMiddleware;
use Quiote\Testing\PhpUnitTestCase;

final class McpAuthMiddlewarePassthroughHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;

        return (new Psr17Factory())->createResponse(200);
    }
}

/**
 * Bearer-token auth gate in front of the MCP HTTP endpoint. Binds a
 * {@see StaticTokenAuthenticator} directly into the "web"
 * context's container for each test rather than going through McpPlugin, to
 * isolate the middleware's own request-handling logic from plugin wiring
 * (covered separately by McpPluginTest).
 */
final class McpAuthMiddlewareTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        Config::remove('mcp.enabled');
        Config::remove('mcp.path');
        Config::remove('mcp.auth');
    }

    private function bindAuthenticator(?string $expectedToken): void
    {
        Context::getInstance('web')->getContainer()->set(
            McpAuthenticatorInterface::class,
            new StaticTokenAuthenticator($expectedToken),
        );
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        Config::set('mcp.enabled', true, true);
        $this->bindAuthenticator('secret');

        $middleware = new McpAuthMiddleware('web');
        $next = new McpAuthMiddlewarePassthroughHandler();
        $response = $middleware->process((new Psr17Factory())->createServerRequest('POST', '/mcp'), $next);

        $this->assertFalse($next->called);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testWrongTokenIsRejected(): void
    {
        Config::set('mcp.enabled', true, true);
        $this->bindAuthenticator('secret');

        $middleware = new McpAuthMiddleware('web');
        $next = new McpAuthMiddlewarePassthroughHandler();
        $request = (new Psr17Factory())->createServerRequest('POST', '/mcp')->withHeader('Authorization', 'Bearer wrong');
        $response = $middleware->process($request, $next);

        $this->assertFalse($next->called);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCorrectTokenIsAccepted(): void
    {
        Config::set('mcp.enabled', true, true);
        $this->bindAuthenticator('secret');

        $middleware = new McpAuthMiddleware('web');
        $next = new McpAuthMiddlewarePassthroughHandler();
        $request = (new Psr17Factory())->createServerRequest('POST', '/mcp')->withHeader('Authorization', 'Bearer secret');
        $response = $middleware->process($request, $next);

        $this->assertTrue($next->called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNonMatchingPathDelegatesWithoutCheckingAuth(): void
    {
        Config::set('mcp.enabled', true, true);
        $this->bindAuthenticator('secret');

        $middleware = new McpAuthMiddleware('web');
        $next = new McpAuthMiddlewarePassthroughHandler();
        $response = $middleware->process((new Psr17Factory())->createServerRequest('GET', '/not-mcp'), $next);

        $this->assertTrue($next->called);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * PSR-11 get() is typed mixed, so a container binding that answers the
     * authenticator id with something else would otherwise blow up on the
     * authenticate() call with a bare TypeError. The middleware names the
     * offending service instead, and never lets the request through.
     */
    public function testServiceThatIsNotAnAuthenticatorIsReported(): void
    {
        Config::set('mcp.enabled', true, true);
        Context::getInstance('web')->getContainer()->set(McpAuthenticatorInterface::class, new stdClass());

        $middleware = new McpAuthMiddleware('web');
        $next = new McpAuthMiddlewarePassthroughHandler();
        $request = (new Psr17Factory())->createServerRequest('POST', '/mcp')->withHeader('Authorization', 'Bearer secret');

        try {
            $middleware->process($request, $next);
            $this->fail('a mis-registered authenticator service must not be used');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(McpAuthenticatorInterface::class, $e->getMessage());
            $this->assertStringContainsString('stdClass', $e->getMessage());
        } finally {
            $this->bindAuthenticator('secret');
        }

        $this->assertFalse($next->called);
    }

    public function testAuthNoneDelegatesWithoutCheckingAuth(): void
    {
        Config::set('mcp.enabled', true, true);
        Config::set('mcp.auth', 'none', true);
        $this->bindAuthenticator('secret');

        $middleware = new McpAuthMiddleware('web');
        $next = new McpAuthMiddlewarePassthroughHandler();
        $response = $middleware->process((new Psr17Factory())->createServerRequest('POST', '/mcp'), $next);

        $this->assertTrue($next->called);
    }
}
