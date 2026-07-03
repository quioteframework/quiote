<?php

use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;
use Quiote\Mcp\Bridge\ActionToolAdapter;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The actions-as-tools bridge dispatch mechanism (docs/MCP_SERVER_PLAN.md §7):
 * builds a synthetic request for the target route and drives it through the
 * real {@see \Quiote\Context::handle()} pipeline -- the same DI, verb
 * dispatch, and (were the fixture action to declare any) validation a normal
 * HTTP request would get. Exercised against the "mcp-action-tool-test"
 * context / GreetAction fixture (tests/sandbox/app/Modules/McpActionTool).
 */
final class ActionToolAdapterTest extends PhpUnitTestCase
{
    private function gateway(): ClientGateway
    {
        return (new ReflectionClass(ClientGateway::class))->newInstanceWithoutConstructor();
    }

    public function testDispatchesToTheRealActionAndReturnsItsResponseBody(): void
    {
        $adapter = new ActionToolAdapter('mcp-action-tool-test', 'mcp_action_tool_test.greet', 'GET');

        $result = $adapter->execute(['name' => 'Ada'], $this->gateway());

        $this->assertSame('Hello, Ada!', $result);
    }

    public function testMissingRequiredPathParameterRaisesAToolCallException(): void
    {
        $adapter = new ActionToolAdapter('mcp-action-tool-test', 'mcp_action_tool_test.greet', 'GET');

        $this->expectException(ToolCallException::class);
        $adapter->execute([], $this->gateway());
    }

    public function testUnknownRouteRaisesAToolCallException(): void
    {
        $adapter = new ActionToolAdapter('mcp-action-tool-test', 'no.such.route', 'GET');

        $this->expectException(ToolCallException::class);
        $adapter->execute([], $this->gateway());
    }

    public function testExtraNonPathArgumentsAreSentAsQueryParameters(): void
    {
        $adapter = new ActionToolAdapter('mcp-action-tool-test', 'mcp_action_tool_test.greet', 'GET');

        // "greeting_suffix" isn't a path variable of the route -- it should
        // ride along as a query param rather than breaking URL generation.
        $result = $adapter->execute(['name' => 'Ada', 'greeting_suffix' => '!!'], $this->gateway());

        $this->assertSame('Hello, Ada!', $result, 'the view ignores the extra param, but the call must not fail because of it');
    }
}
