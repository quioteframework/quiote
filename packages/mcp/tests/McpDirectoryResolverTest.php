<?php

use PHPUnit\Framework\TestCase;
use Quiote\Mcp\Compiler\McpDirectoryResolver;

/**
 * Resolves the plain-class attribute-discovery scan set: one directory per
 * module that actually has an `Mcp/` subdirectory. Fixtures:
 * tests/sandbox/app/Modules/McpDiscovery/Mcp/GreeterTool.php (has one),
 * tests/sandbox/app/Modules/McpActionTool/ (does not).
 */
final class McpDirectoryResolverTest extends TestCase
{
    private const SANDBOX_MODULES = __DIR__ . '/../../../tests/sandbox/app/Modules';

    public function testFindsAModulesMcpSubdirectory(): void
    {
        $dirs = (new McpDirectoryResolver())->resolve([self::SANDBOX_MODULES]);

        $this->assertContains(
            realpath(self::SANDBOX_MODULES . '/McpDiscovery/Mcp'),
            array_map('realpath', $dirs),
        );
    }

    public function testIgnoresModulesWithoutAnMcpSubdirectory(): void
    {
        $dirs = (new McpDirectoryResolver())->resolve([self::SANDBOX_MODULES]);

        foreach ($dirs as $dir) {
            $this->assertStringNotContainsString('McpActionTool', $dir);
        }
    }

    public function testReturnsEmptyForANonExistentModuleDirectory(): void
    {
        $dirs = (new McpDirectoryResolver())->resolve([sys_get_temp_dir() . '/does-not-exist-' . uniqid()]);

        $this->assertSame([], $dirs);
    }

    public function testReturnsEmptyForAModuleDirectoryWithNoMcpSubdirectoriesAtAll(): void
    {
        $dirs = (new McpDirectoryResolver())->resolve([sys_get_temp_dir()]);

        $this->assertSame([], $dirs);
    }

    public function testMergesMultipleModuleDirectories(): void
    {
        $dirs = (new McpDirectoryResolver())->resolve([self::SANDBOX_MODULES, sys_get_temp_dir()]);

        $this->assertCount(1, $dirs);
    }
}
