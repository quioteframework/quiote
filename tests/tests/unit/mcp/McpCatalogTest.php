<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Mcp\McpCatalog;

/**
 * McpCatalog is a plain static registry (mirrors MiddlewareCatalog): entries
 * are stored verbatim so McpServer can forward them to `Mcp\Server\Builder`
 * without this class knowing anything about the SDK.
 */
final class McpCatalogTest extends TestCase
{
    #[Before]
    #[After]
    public function resetCatalog(): void
    {
        McpCatalog::reset();
    }

    public function testAddToolIsRecordedVerbatim(): void
    {
        McpCatalog::addTool(['App\\Foo', 'bar'], 'foo_bar', null, 'does a thing', ['type' => 'object'], null);

        $tools = McpCatalog::tools();
        $this->assertCount(1, $tools);
        $this->assertSame(['App\\Foo', 'bar'], $tools[0]['handler']);
        $this->assertSame('foo_bar', $tools[0]['name']);
        $this->assertSame('does a thing', $tools[0]['description']);
        $this->assertSame(['type' => 'object'], $tools[0]['inputSchema']);
    }

    public function testAddResourceIsRecordedVerbatim(): void
    {
        McpCatalog::addResource('App\\Res', 'app://widgets', 'widgets', null, 'the widgets', 'application/json');

        $resources = McpCatalog::resources();
        $this->assertCount(1, $resources);
        $this->assertSame('App\\Res', $resources[0]['handler']);
        $this->assertSame('app://widgets', $resources[0]['uri']);
        $this->assertSame('application/json', $resources[0]['mimeType']);
    }

    public function testAddPromptIsRecordedVerbatim(): void
    {
        McpCatalog::addPrompt('App\\Prompt', 'greeting', null, 'says hello');

        $prompts = McpCatalog::prompts();
        $this->assertCount(1, $prompts);
        $this->assertSame('App\\Prompt', $prompts[0]['handler']);
        $this->assertSame('greeting', $prompts[0]['name']);
    }

    public function testResetClearsAllThreeRegistries(): void
    {
        McpCatalog::addTool('App\\Foo');
        McpCatalog::addResource('App\\Res', 'app://x');
        McpCatalog::addPrompt('App\\Prompt');

        McpCatalog::reset();

        $this->assertSame([], McpCatalog::tools());
        $this->assertSame([], McpCatalog::resources());
        $this->assertSame([], McpCatalog::prompts());
    }
}
