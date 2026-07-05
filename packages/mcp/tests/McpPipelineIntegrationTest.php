<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Mcp\Auth\McpAuthenticatorInterface;
use Quiote\Mcp\Auth\StaticTokenAuthenticator;
use Quiote\Mcp\McpCatalog;
use Quiote\Mcp\McpPlugin;
use Quiote\Mcp\Middleware\McpAuthMiddleware;
use Quiote\Mcp\Middleware\McpEndpointMiddleware;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Testing\PhpUnitTestCase;

final class McpPipelineIntegrationGreeter
{
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}

/**
 * End-to-end: McpPlugin's contributions actually take effect inside a real
 * MiddlewarePipeline, in the right relative order (auth before endpoint,
 * both before SecurityMiddleware), not just "MiddlewareCatalog holds an
 * entry" as the narrower McpPluginTest checks, and that a real
 * request/response round trip through the "web" context's full pipeline
 * actually reaches the MCP server. (Two malformed legacy routes in this
 * fixture app used to make *any* dispatch through this context crash before
 * ever reaching these middleware -- fixed in
 * tests/sandbox/app/Routing/Generated/Modules/{BlogRoutes,AdminRoutes}.php.)
 */
final class McpPipelineIntegrationTest extends PhpUnitTestCase
{
    private ?string $originalDefaultContext = null;

    #[Before]
    #[After]
    public function resetState(): void
    {
        MiddlewareCatalog::reset();
        McpCatalog::reset();
        Config::remove('mcp.enabled');
        Config::remove('mcp.transports');
        Config::remove('mcp.auth');
        Config::remove('mcp.auth_token');
        Config::remove('mcp.path');
    }

    /**
     * McpPlugin::register() binds its context-scoped middleware (McpAuthMiddleware,
     * McpEndpointMiddleware) to whatever core.default_context resolves to, not
     * necessarily "web" -- but every test in this file drives its pipeline via the
     * hardcoded Context::getInstance('web'). Pin it to "web" for the duration of each
     * test so the plugin's registrations and the test's manual container overrides
     * (e.g. the McpAuthenticatorInterface binding) land on the same Context, and
     * restore whatever it was before so other tests relying on the app's real
     * core.default_context aren't affected.
     */
    #[Before]
    public function pinDefaultContextToWeb(): void
    {
        $this->originalDefaultContext = Config::has('core.default_context')
            ? Config::getString('core.default_context')
            : null;
        Config::set('core.default_context', 'web', true);
    }

    #[After]
    public function restoreDefaultContext(): void
    {
        if ($this->originalDefaultContext !== null) {
            Config::set('core.default_context', $this->originalDefaultContext, true);
        }
    }

    /** Registers McpPlugin's contributions directly, bypassing PluginManager::bootFromConfig()'s once-only guard (already tripped by the sandbox app's own bootstrap). */
    private function registerPluginDirectly(): void
    {
        (new McpPlugin())->register(new PluginRegistrar('quiote/mcp'));
    }

    private function pipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline(Context::getInstance('web'));
    }

    public function testOrderIsAuthThenEndpointThenSecurity(): void
    {
        Config::set('mcp.transports', ['http'], true);
        $this->registerPluginDirectly();

        $pipeline = $this->pipeline();
        try {
            $pipeline->handle(new ServerRequest('GET', 'http://localhost/not-mcp'));
        } catch (\Throwable) {
            // debugStack is populated during build, before the stack runs.
        }

        $order = $pipeline->debugStack();
        $authPos = array_search(McpAuthMiddleware::class, $order, true);
        $endpointPos = array_search(McpEndpointMiddleware::class, $order, true);
        $securityPos = array_search(SecurityMiddleware::class, $order, true);

        $this->assertNotFalse($authPos);
        $this->assertNotFalse($endpointPos);
        $this->assertNotFalse($securityPos);
        $this->assertLessThan($endpointPos, $authPos, 'auth must run before the endpoint');
        $this->assertLessThan($securityPos, $endpointPos, "the endpoint must run before Quiote's own SecurityMiddleware");
    }

    private function jsonRpcRequest(array $body): ServerRequest
    {
        $factory = new Psr17Factory();

        return (new ServerRequest('POST', 'http://localhost/mcp'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function testUnauthenticatedRequestIsRejectedBeforeReachingTheEndpoint(): void
    {
        Config::set('mcp.transports', ['http'], true);
        Config::set('mcp.enabled', true, true);
        Context::getInstance('web')->getContainer()->set(McpAuthenticatorInterface::class, new StaticTokenAuthenticator('secret'));
        $this->registerPluginDirectly();

        $response = $this->pipeline()->handle($this->jsonRpcRequest([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
        ]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testAuthenticatedRequestReachesTheServerAndGetsAJsonRpcResponse(): void
    {
        Config::set('mcp.transports', ['http'], true);
        Config::set('mcp.enabled', true, true);
        McpCatalog::addTool([McpPipelineIntegrationGreeter::class, 'greet'], 'greet');
        Context::getInstance('web')->getContainer()->set(McpAuthenticatorInterface::class, new StaticTokenAuthenticator('secret'));
        $this->registerPluginDirectly();

        $response = $this->pipeline()->handle(
            $this->jsonRpcRequest([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
            ])->withHeader('Authorization', 'Bearer secret'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('result', $payload);
    }
}
