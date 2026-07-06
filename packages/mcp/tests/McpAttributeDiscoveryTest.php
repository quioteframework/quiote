<?php

use Mcp\Server\Transport\InMemoryTransport;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\Mcp\McpCatalog;
use Quiote\Mcp\McpConfig;
use Quiote\Mcp\McpServer;

/**
 * Plain-class `#[McpTool]` attribute discovery (as opposed to the
 * actions-as-tools bridge, which only reads `#[McpTool]` on `#[Route]`
 * actions): {@see \Quiote\Mcp\McpServer::addAttributeDiscovery()} wires the
 * SDK's own `Mcp\Capability\Discovery\Discoverer` via `Builder::setDiscovery()`,
 * scoped to `tests/sandbox/app/Modules/McpDiscovery/Mcp/GreeterTool.php`
 * (no `#[Route]` at all).
 */
final class McpAttributeDiscoveryTest extends TestCase
{
    private const SANDBOX_MODULES = __DIR__ . '/../../../tests/sandbox/app/Modules';

    #[Before]
    #[After]
    public function resetCatalog(): void
    {
        McpCatalog::reset();
    }

    private function config(bool $discoverAttributes, bool $discoveryCache = false): McpConfig
    {
        return new McpConfig(
            enabled: true,
            transports: ['stdio'],
            path: '/mcp',
            protocolVersion: '2025-11-25',
            stateless: true,
            serverName: 'testing',
            serverVersion: '1.0.0',
            auth: 'none',
            exposeActions: false,
            moduleDirs: [self::SANDBOX_MODULES],
            discoverAttributes: $discoverAttributes,
            discoveryCache: $discoveryCache,
        );
    }

    public function testDisabledByDefaultLeavesTheDiscoveredToolUnregistered(): void
    {
        $server = (new McpServer(new Container(), 'testing'))->build($this->config(discoverAttributes: false));

        $response = $this->call($server, 'tools/list', []);

        $names = array_column($response['result']['tools'], 'name');
        $this->assertNotContains('greet_via_discovery', $names);
    }

    public function testDiscoversAndInvokesAPlainClassToolWithNoRouteAttribute(): void
    {
        $server = (new McpServer(new Container(), 'testing'))->build($this->config(discoverAttributes: true));

        $listResponse = $this->call($server, 'tools/list', []);
        $names = array_column($listResponse['result']['tools'], 'name');
        $this->assertContains('greet_via_discovery', $names);

        $callResponse = $this->call($server, 'tools/call', ['name' => 'greet_via_discovery', 'arguments' => ['name' => 'Ada']]);
        $this->assertArrayNotHasKey('error', $callResponse);
        $this->assertSame('Hello from discovery, Ada!', $callResponse['result']['content'][0]['text']);
    }

    public function testWorksWithTheDiscoveryCacheEnabled(): void
    {
        $cacheDir = sys_get_temp_dir() . '/quiote-mcp-discovery-test-' . uniqid();
        $hadPrevious = \Quiote\Config\Config::has('core.cache_dir');
        $previous = $hadPrevious ? \Quiote\Config\Config::get('core.cache_dir') : null;
        \Quiote\Config\Config::set('core.cache_dir', $cacheDir, true);

        try {
            $server = (new McpServer(new Container(), 'testing'))->build($this->config(discoverAttributes: true, discoveryCache: true));

            $callResponse = $this->call($server, 'tools/call', ['name' => 'greet_via_discovery', 'arguments' => ['name' => 'Grace']]);
            $this->assertSame('Hello from discovery, Grace!', $callResponse['result']['content'][0]['text']);
        } finally {
            if ($hadPrevious) {
                \Quiote\Config\Config::set('core.cache_dir', $previous, true);
            } else {
                \Quiote\Config\Config::remove('core.cache_dir');
            }
            (new \Symfony\Component\Filesystem\Filesystem())->remove($cacheDir);
        }
    }

    /** @param array<string, mixed> $params */
    private function call(\Mcp\Server $server, string $method, array $params): array
    {
        $transport = new RecordingDiscoveryTransport([
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'phpunit', 'version' => '1.0'],
                ],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => $method,
                'params' => $params,
            ], JSON_THROW_ON_ERROR),
        ]);

        $server->run($transport);

        return json_decode($transport->sent[1], true, flags: JSON_THROW_ON_ERROR);
    }
}

/**
 * Same drain-after-each-message pattern as {@see RecordingInMemoryTransport}
 * in McpServerTest.php; duplicated locally to keep this file self-contained.
 */
final class RecordingDiscoveryTransport extends InMemoryTransport
{
    /** @var list<string> */
    private array $queue;

    /** @var list<string> */
    public array $sent = [];

    /** @param list<string> $messages */
    public function __construct(array $messages)
    {
        parent::__construct([]);
        $this->queue = $messages;
    }

    public function listen(): mixed
    {
        foreach ($this->queue as $message) {
            $this->handleMessage($message, $this->sessionId);
            foreach ($this->getOutgoingMessages($this->sessionId) as $outgoing) {
                $this->sent[] = $outgoing['message'];
            }
        }
        $this->handleSessionEnd($this->sessionId);

        return null;
    }
}
