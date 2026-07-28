<?php
declare(strict_types=1);

namespace Quiote\Openapi;

use Quiote\Config\Config;

/**
 * The document-level knobs {@see OpenApiGenerator} can't derive from code:
 * `info`, `servers`, and which routes to describe at all. Everything else in
 * the emitted spec comes from the route table and the actions' own validator
 * declarations, so this stays deliberately small.
 *
 * {@see fromConfig()} reads the `core.openapi.*` settings, so an app declares
 * its API metadata once in settings.* and both `openapi:generate` and any
 * programmatic caller agree on it.
 * @since      1.2.5
 */
final readonly class OpenApiOptions
{
    /**
     * @param list<array{url: string, description?: string}> $servers
     * @param list<string> $excludeRoutes  fnmatch() patterns matched against route names; a matching route is left out.
     * @param list<string> $modules        Only describe routes belonging to these modules (case-insensitive); empty means all.
     * @param bool $problemResponses       Emit the RFC 9457 error responses (400 for routes with validators, 500) Quiote's pipeline actually returns.
     * @param bool $useActionDocblocks     Use each action class's docblock as its operation summary/description. Turn off for an app whose action docblocks are internal notes rather than API prose.
     */
    public function __construct(
        public string $title = 'API',
        public string $version = '1.0.0',
        public ?string $description = null,
        public array $servers = [],
        public array $excludeRoutes = [],
        public array $modules = [],
        public bool $problemResponses = true,
        public bool $useActionDocblocks = true,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            title: Config::getString('core.openapi.title', Config::getString('core.app_name', 'API')),
            version: Config::getString('core.openapi.version', '1.0.0'),
            description: Config::getNullableString('core.openapi.description') ?: null,
            servers: self::normalizeServers(Config::getArray('core.openapi.servers', [])),
            excludeRoutes: array_values(Config::getStringList('core.openapi.exclude_routes', [])),
            modules: array_values(Config::getStringList('core.openapi.modules', [])),
            problemResponses: Config::getBool('core.openapi.problem_responses', true),
            useActionDocblocks: Config::getBool('core.openapi.use_action_docblocks', true),
        );
    }

    /** Whether $routeName is excluded by any of the configured fnmatch patterns. */
    public function excludes(string $routeName): bool
    {
        foreach ($this->excludeRoutes as $pattern) {
            if ($pattern === $routeName || fnmatch($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $module is in scope (always true when no module filter is set). */
    public function coversModule(string $module): bool
    {
        if ($this->modules === []) {
            return true;
        }
        foreach ($this->modules as $candidate) {
            if (strcasecmp($candidate, $module) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Accepts the two shapes a settings file can plausibly use -- a bare list
     * of URLs, or a list of `{url, description}` maps -- and normalizes both to
     * the OpenAPI Server Object shape.
     * @param array<mixed> $servers
     * @return list<array{url: string, description?: string}>
     */
    public static function normalizeServers(array $servers): array
    {
        $normalized = [];
        foreach ($servers as $server) {
            if (is_string($server)) {
                if ($server !== '') {
                    $normalized[] = ['url' => $server];
                }
                continue;
            }
            if (!is_array($server)) {
                continue;
            }
            $url = $server['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $entry = ['url' => $url];
            $description = $server['description'] ?? null;
            if (is_string($description) && $description !== '') {
                $entry['description'] = $description;
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }
}
