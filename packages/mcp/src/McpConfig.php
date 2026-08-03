<?php

namespace Quiote\Mcp;

use Quiote\Config\Config;
use Quiote\Exception\QuioteException;

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
     * The accepted values of `mcp.auth`.
     * @var list<string>
     */
    public const array AUTH_MODES = ['none', 'bearer', 'oauth2'];

    /**
     * @param list<string> $transports
     * @param list<string> $moduleDirs
     * @param list<string> $oauthScopesSupported
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
        public readonly ?string $oauthIssuer,
        public readonly ?string $oauthAudience,
        public readonly ?string $oauthJwksUri,
        public readonly array $oauthScopesSupported,
        public readonly int $oauthCacheTtl,
    ) {
        // An unrecognised mode currently degrades to static-token auth, which
        // fails closed -- but silently, and against the wrong credential store.
        // A typo ("oauth", "OAuth2", a stray space) would leave an operator who
        // configured an issuer and audience believing the endpoint validates
        // JWTs while it is really demanding an mcp.auth_token they never set.
        if (!in_array($this->auth, self::AUTH_MODES, true)) {
            throw new QuioteException(sprintf(
                'mcp.auth is "%s", which is not a recognised mode. Expected one of: %s.',
                $this->auth,
                implode(', ', self::AUTH_MODES),
            ));
        }

        if ($this->auth === 'oauth2' && ($this->oauthIssuer === null || $this->oauthAudience === null)) {
            throw new QuioteException(
                'mcp.auth is "oauth2" but "mcp.oauth.issuer" and/or "mcp.oauth.audience" are missing. '
                . 'Both are required to validate tokens against an external OAuth2/OIDC provider.'
            );
        }
    }

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
            oauthIssuer: Config::getNullableString('mcp.oauth.issuer'),
            oauthAudience: Config::getNullableString('mcp.oauth.audience'),
            oauthJwksUri: Config::getNullableString('mcp.oauth.jwks_uri'),
            oauthScopesSupported: array_values(Config::getStringList('mcp.oauth.scopes_supported', [])),
            oauthCacheTtl: (int) Config::getInt('mcp.oauth.cache_ttl', 3600),
        );
    }
}
