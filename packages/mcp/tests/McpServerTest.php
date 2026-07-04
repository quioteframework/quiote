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
 * A plain, autowireable tool handler -- never explicitly bound to the
 * container -- exercising the exact path {@see \Quiote\Mcp\Bridge\ContainerAdapter}
 * exists for (mcp/sdk's ReferenceHandler resolving it via DI, not `new`).
 */
final class McpServerTestGreeterTool
{
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}

/**
 * Drives a fixed message queue and captures outgoing frames. {@see InMemoryTransport}
 * queues responses on the session rather than passing them to send() (that's
 * only drained by transports that implement their own flush loop, e.g.
 * StdioTransport::flushOutgoingMessages()) -- so this reimplements that same
 * drain-after-each-message loop instead of relying on send() being called.
 */
final class RecordingInMemoryTransport extends InMemoryTransport
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

/**
 * Builds a real `Mcp\Server` from the catalog + drives a full JSON-RPC
 * initialize -> tools/call round trip over {@see InMemoryTransport}, proving
 * the facade actually wires a registered tool through Quiote's own DI
 * container end to end.
 */
final class McpServerTest extends TestCase
{
    #[Before]
    #[After]
    public function resetCatalog(): void
    {
        McpCatalog::reset();
    }

    public function testBuildReturnsAnSdkServer(): void
    {
        McpCatalog::addTool([McpServerTestGreeterTool::class, 'greet'], 'greet');

        $server = (new McpServer(new Container(), 'testing'))->build(McpConfig::fromConfig());

        $this->assertInstanceOf(\Mcp\Server::class, $server);
    }

    public function testRegisteredToolIsInvokedThroughTheContainer(): void
    {
        McpCatalog::addTool([McpServerTestGreeterTool::class, 'greet'], 'greet');

        $server = (new McpServer(new Container(), 'testing'))->build(McpConfig::fromConfig());

        $transport = new RecordingInMemoryTransport([
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
                'method' => 'tools/call',
                'params' => ['name' => 'greet', 'arguments' => ['name' => 'Ada']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $server->run($transport);

        $this->assertCount(2, $transport->sent, 'expected one response for "initialize" and one for "tools/call"');

        $toolCallResponse = json_decode($transport->sent[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $toolCallResponse['id']);
        $this->assertArrayNotHasKey('error', $toolCallResponse);
        $this->assertSame('Hello, Ada!', $toolCallResponse['result']['content'][0]['text']);
    }
}
