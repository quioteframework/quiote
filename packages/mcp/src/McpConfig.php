<?php

namespace Quiote\Mcp;

use Quiote\Config\Config;

/**
 * Typed snapshot of the `mcp.*` settings family.
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
        public readonly bool $discoverAttributes,
        public readonly bool $discoveryCache,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: Config::getBool('mcp.enabled', false),
            transports: array_values(Config::getStringList('mcp.transports', ['stdio'])),
            path: Config::getString('mcp.path', '/mcp'),
            protocolVersion: Config::getString('mcp.protocol_version', '2025-11-25'),
            stateless: Config::getBool('mcp.stateless', true),
            serverName: Config::getString('mcp.server_name', Config::getString('core.app_name', 'quiote-app')),
            serverVersion: Config::getString('mcp.server_version', '1.0.0'),
            auth: Config::getString('mcp.auth', 'bearer'),
            exposeActions: Config::getBool('mcp.expose_actions', false),
            moduleDirs: array_values(Config::getStringList('mcp.module_dirs', [])),
            discoverAttributes: Config::getBool('mcp.discover_attributes', false),
            discoveryCache: Config::getBool('mcp.discovery_cache', true),
        );
    }
}
