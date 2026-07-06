<?php

namespace Quiote\Mcp\Compiler;

use Quiote\Config\Config;
use Quiote\Plugin\PluginManager;

/**
 * Resolves the plain-class attribute-discovery scan set: every existing
 * `{ModuleDir}/{Module}/Mcp/` subdirectory across the app's module
 * directory plus any plugin-contributed module directories -- mirroring the
 * `{Module}/Actions/`, `{Module}/Validate/` per-module convention the rest of
 * the framework already uses, scoped to a `Mcp/` subtree so this scan is
 * cheap and doesn't also walk every action/controller class in the app.
 */
final class McpDirectoryResolver
{
    /**
     * @param iterable<string>|null $moduleDirs Defaults to
     *        [core.module_dir, ...PluginManager::moduleDirectories()].
     * @return list<string>
     */
    public function resolve(?iterable $moduleDirs = null): array
    {
        $moduleDirs = $moduleDirs !== null
            ? $moduleDirs
            : [Config::getString('core.module_dir'), ...PluginManager::moduleDirectories()];

        $scanDirs = [];
        foreach ($moduleDirs as $moduleDir) {
            $moduleDir = rtrim((string) $moduleDir, '/');
            if ($moduleDir === '' || !is_dir($moduleDir)) {
                continue;
            }

            $entries = scandir($moduleDir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $mcpDir = $moduleDir . '/' . $entry . '/Mcp';
                if (is_dir($mcpDir)) {
                    $scanDirs[] = $mcpDir;
                }
            }
        }

        return $scanDirs;
    }
}
