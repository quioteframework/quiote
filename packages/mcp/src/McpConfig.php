<?php

namespace Quiote\Mcp;

use Quiote\Config\Config;

/**
 * Typed snapshot of the `mcp.*` settings family (see docs/MCP_SERVER_PLAN.md §11).
 * Defaults here are read as fallbacks only — {@see McpPlugin} is what actually
 * publishes them into {@see Config} via `configDefault()`, so an app that adds
 * `McpPlugin` to its `plugins` key without further configuration still gets a
 * sane, opt-in-safe setup (`enabled = false`).
 */
final class McpConfig
{
    /**
     * @param list<string> $transports
     * @param list<string> $moduleDirs
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $transports,
        public readonly string $path,
        public readonly string $protocolVersion,
        public readonly bool $stateless,
        public readonly string $serverName,
        public readonly string $serverVersion,
        public readonly string $auth,
        public readonly bool $exposeActions,
        public readonly array $moduleDirs,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) Config::get('mcp.enabled', false),
            transports: array_values((array) Config::get('mcp.transports', ['stdio'])),
            path: (string) Config::get('mcp.path', '/mcp'),
            protocolVersion: (string) Config::get('mcp.protocol_version', '2025-11-25'),
            stateless: (bool) Config::get('mcp.stateless', true),
            serverName: (string) Config::get('mcp.server_name', (string) Config::get('core.app_name', 'quiote-app')),
            serverVersion: (string) Config::get('mcp.server_version', '1.0.0'),
            auth: (string) Config::get('mcp.auth', 'bearer'),
            exposeActions: (bool) Config::get('mcp.expose_actions', false),
            moduleDirs: array_values((array) Config::get('mcp.module_dirs', [])),
        );
    }
}
