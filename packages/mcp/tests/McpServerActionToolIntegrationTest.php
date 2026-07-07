<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Mcp\McpCatalog;
use Quiote\Mcp\McpConfig;
use Quiote\Mcp\McpServer;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The full actions-as-tools bridge, end to end: `mcp.expose_actions` on
 * -> ActionToolScanner discovers GreetAction (the
 * "mcp-action-tool-test" fixture) -> McpServer::build() registers it as an
 * explicit tool -> a real `tools/call` JSON-RPC round trip actually dispatches
 * to the action through Context::handle() and returns its rendered output.
 * Reuses {@see RecordingInMemoryTransport} from McpServerTest.php.
 */
final class McpServerActionToolIntegrationTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetState(): void
    {
        McpCatalog::reset();
        Config::remove('mcp.expose_actions');
        Config::remove('mcp.module_dirs');
    }

    public function testActionToolCanBeCalledEndToEnd(): void
    {
        Config::set('mcp.expose_actions', true, true);

        $container = Context::getInstance('mcp-action-tool-test')->getContainer();
        $server = (new McpServer($container, 'mcp-action-tool-test'))->build(McpConfig::fromConfig());

        $transport = new RecordingInMemoryTransport([
            json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
            ], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode([
                'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                'params' => ['name' => 'greet_via_action', 'arguments' => ['name' => 'Ada']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $server->run($transport);

        $this->assertCount(2, $transport->sent);
        $response = json_decode($transport->sent[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertSame('Hello, Ada!', $response['result']['content'][0]['text']);
    }

    public function testDerivedSchemaListsTheToolWithItsValidatorConstraints(): void
    {
        Config::set('mcp.expose_actions', true, true);

        $container = Context::getInstance('mcp-action-tool-test')->getContainer();
        $server = (new McpServer($container, 'mcp-action-tool-test'))->build(McpConfig::fromConfig());

        $transport = new RecordingInMemoryTransport([
            json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
            ], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], JSON_THROW_ON_ERROR),
        ]);

        $server->run($transport);

        $response = json_decode($transport->sent[1], true, flags: JSON_THROW_ON_ERROR);
        $tools = [];
        foreach ($response['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }

        $this->assertArrayHasKey('greet_via_action', $tools);
        $schema = $tools['greet_via_action']['inputSchema'];
        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame(2, $schema['properties']['name']['minLength']);
        $this->assertSame(50, $schema['properties']['name']['maxLength']);
        $this->assertContains('name', $schema['required']);
    }

    public function testCallViolatingTheDerivedSchemaIsRejectedBeforeDispatch(): void
    {
        Config::set('mcp.expose_actions', true, true);

        $container = Context::getInstance('mcp-action-tool-test')->getContainer();
        $server = (new McpServer($container, 'mcp-action-tool-test'))->build(McpConfig::fromConfig());

        // "A" is one char; the derived schema requires minLength 2, so the SDK's
        // own schema validation rejects the call as invalid params before it
        // ever reaches the action -- the payoff of deriving the schema at all.
        $transport = new RecordingInMemoryTransport([
            json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
            ], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode([
                'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                'params' => ['name' => 'greet_via_action', 'arguments' => ['name' => 'A']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $server->run($transport);

        $response = json_decode($transport->sent[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32602, $response['error']['code'], 'invalid params');
    }

    /**
     * Regression guard: MultiVerbAction has no XML validator file and no
     * fluent register*Validators() -- ActionToolScanner can't derive
     * anything, so its tool gets the permissive fallback schema
     * (`properties: {}`, nothing required). Before the fix,
     * McpServer::normalizeRequiredList(null) returned null, which survived
     * into the JSON-encoded schema as `"required":null` -- valid PHP, but
     * invalid per the JSON Schema meta-schema opis/json-schema checks
     * against, so *every* `tools/call` against a fallback-schema tool
     * failed with a -32602 "required must be an array of strings" error
     * before the actual arguments were ever looked at, regardless of what
     * was sent.
     */
    public function testCallingAToolWithThePermissiveFallbackSchemaSucceeds(): void
    {
        Config::set('mcp.expose_actions', true, true);

        $container = Context::getInstance('mcp-action-tool-test')->getContainer();
        $server = (new McpServer($container, 'mcp-action-tool-test'))->build(McpConfig::fromConfig());

        $transport = new RecordingInMemoryTransport([
            json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
            ], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], JSON_THROW_ON_ERROR),
            json_encode([
                'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => 'multi_verb_via_action', 'arguments' => []],
            ], JSON_THROW_ON_ERROR),
        ]);

        $server->run($transport);

        $listResponse = json_decode($transport->sent[1], true, flags: JSON_THROW_ON_ERROR);
        $tools = [];
        foreach ($listResponse['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool;
        }
        $this->assertArrayHasKey('multi_verb_via_action', $tools);
        $this->assertSame([], $tools['multi_verb_via_action']['inputSchema']['properties']);
        $this->assertSame([], $tools['multi_verb_via_action']['inputSchema']['required']);

        $callResponse = json_decode($transport->sent[2], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $callResponse, 'A fallback-schema tool call must not be rejected by schema validation before dispatch');
        $this->assertArrayHasKey('result', $callResponse, 'The call must actually dispatch to the action, not just avoid an error');
    }

    public function testDisabledByDefaultMeansTheActionToolIsNotRegistered(): void
    {
        $container = Context::getInstance('mcp-action-tool-test')->getContainer();
        $server = (new McpServer($container, 'mcp-action-tool-test'))->build(McpConfig::fromConfig());

        $transport = new RecordingInMemoryTransport([
            json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']],
            ], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
            json_encode([
                'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                'params' => ['name' => 'greet_via_action', 'arguments' => ['name' => 'Ada']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $server->run($transport);

        $response = json_decode($transport->sent[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('error', $response, 'expose_actions defaults to false, so the tool should not exist');
    }
}
