<?php

namespace Quiote\Mcp;

use Mcp\Capability\Registry\ElementReference;

/**
 * Process-global registry of MCP tools/resources/prompts, mirroring the static,
 * worker-lifetime pattern of {@see \Quiote\Middleware\MiddlewareCatalog} and
 * {@see \Quiote\Plugin\PluginManager}: entries are added once at boot (via
 * {@see \Quiote\Plugin\PluginRegistrar} or attribute discovery, once that lands)
 * and read once by {@see McpServer::build()} when the server is assembled.
 *
 * Each entry is the argument set for the matching `Mcp\Server\Builder::add*()`
 * call, stored verbatim so `McpServer` can forward it without this class
 * knowing anything about the SDK's types.
 *
 * @phpstan-import-type Handler from ElementReference
 */
final class McpCatalog
{
    /** @var list<array{handler: Handler, name: ?string, title: ?string, description: ?string, inputSchema: ?array<string, mixed>, outputSchema: ?array<string, mixed>}> */
    private static array $tools = [];

    /** @var list<array{handler: Handler, uri: string, name: ?string, title: ?string, description: ?string, mimeType: ?string}> */
    private static array $resources = [];

    /** @var list<array{handler: Handler, name: ?string, title: ?string, description: ?string}> */
    private static array $prompts = [];

    private function __construct() {}

    /**
     * @param \Closure|array{0: object|string, 1: string}|string $handler
     * @param array<string, mixed>|null $inputSchema
     * @param array<string, mixed>|null $outputSchema
     */
    public static function addTool(
        \Closure|array|string $handler,
        ?string $name = null,
        ?string $title = null,
        ?string $description = null,
        ?array $inputSchema = null,
        ?array $outputSchema = null,
    ): void {
        self::$tools[] = compact('handler', 'name', 'title', 'description', 'inputSchema', 'outputSchema');
    }

    /** @param \Closure|array{0: object|string, 1: string}|string $handler */
    public static function addResource(
        \Closure|array|string $handler,
        string $uri,
        ?string $name = null,
        ?string $title = null,
        ?string $description = null,
        ?string $mimeType = null,
    ): void {
        self::$resources[] = compact('handler', 'uri', 'name', 'title', 'description', 'mimeType');
    }

    /** @param \Closure|array{0: object|string, 1: string}|string $handler */
    public static function addPrompt(
        \Closure|array|string $handler,
        ?string $name = null,
        ?string $title = null,
        ?string $description = null,
    ): void {
        self::$prompts[] = compact('handler', 'name', 'title', 'description');
    }

    /** @return list<array{handler: Handler, name: ?string, title: ?string, description: ?string, inputSchema: ?array<string, mixed>, outputSchema: ?array<string, mixed>}> */
    public static function tools(): array
    {
        return self::$tools;
    }

    /** @return list<array{handler: Handler, uri: string, name: ?string, title: ?string, description: ?string, mimeType: ?string}> */
    public static function resources(): array
    {
        return self::$resources;
    }

    /** @return list<array{handler: Handler, name: ?string, title: ?string, description: ?string}> */
    public static function prompts(): array
    {
        return self::$prompts;
    }

    /** Test-only reset (mirrors {@see \Quiote\Middleware\MiddlewareCatalog::reset()}). */
    public static function reset(): void
    {
        self::$tools = [];
        self::$resources = [];
        self::$prompts = [];
    }
}
