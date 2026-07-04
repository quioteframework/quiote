<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Mcp\McpCatalog;
use Quiote\Mcp\Middleware\McpEndpointMiddleware;
use Quiote\Testing\PhpUnitTestCase;

final class McpEndpointMiddlewareGreeter
{
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}

final class McpEndpointMiddlewarePassthroughHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;

        return (new Psr17Factory())->createResponse(200);
    }
}

/**
 * The Streamable-HTTP transport middleware: path matching, the
 * `mcp.enabled` gate, and a real POST /mcp round trip
 * driven through McpServer::handleHttp() -> a real `Mcp\Server`.
 */
final class McpEndpointMiddlewareTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        McpCatalog::reset();
        Config::remove('mcp.enabled');
        Config::remove('mcp.path');
    }

    private function jsonRpcRequest(string $path, array $body): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $stream = $factory->createStream(json_encode($body, JSON_THROW_ON_ERROR));

        return $factory->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    public function testNonMatchingPathDelegatesToTheNextHandler(): void
    {
        Config::set('mcp.enabled', true, true);
        $middleware = new McpEndpointMiddleware('web');
        $next = new McpEndpointMiddlewarePassthroughHandler();

        $response = $middleware->process((new Psr17Factory())->createServerRequest('GET', '/not-mcp'), $next);

        $this->assertTrue($next->called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDisabledDelegatesToTheNextHandlerEvenOnAMatchingPath(): void
    {
        Config::set('mcp.enabled', false, true);
        $middleware = new McpEndpointMiddleware('web');
        $next = new McpEndpointMiddlewarePassthroughHandler();

        $middleware->process($this->jsonRpcRequest('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), $next);

        $this->assertTrue($next->called);
    }

    public function testMatchingPathInitializesTheMcpServerAndReturnsAJsonRpcResponse(): void
    {
        Config::set('mcp.enabled', true, true);
        McpCatalog::addTool([McpEndpointMiddlewareGreeter::class, 'greet'], 'greet');

        $middleware = new McpEndpointMiddleware('web');
        $next = new McpEndpointMiddlewarePassthroughHandler();

        $response = $middleware->process($this->jsonRpcRequest('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
            ],
        ]), $next);

        $this->assertFalse($next->called);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['id']);
        $this->assertArrayHasKey('result', $payload);
        $this->assertNotEmpty($payload['result']['serverInfo']['name']);
    }

    public function testCustomPathIsHonored(): void
    {
        Config::set('mcp.enabled', true, true);
        Config::set('mcp.path', '/custom-mcp', true);

        $middleware = new McpEndpointMiddleware('web');
        $next = new McpEndpointMiddlewarePassthroughHandler();

        $middleware->process($this->jsonRpcRequest('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), $next);
        $this->assertTrue($next->called, 'the default path no longer matches once mcp.path is overridden');
    }
}
