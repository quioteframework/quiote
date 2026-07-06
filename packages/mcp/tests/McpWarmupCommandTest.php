<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Mcp\Console\McpWarmupCommand;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `mcp:warmup` pre-populates the plain-class attribute-discovery cache
 * ({@see \Quiote\Mcp\McpServer::buildDiscoveryCache()}). Gated the same way
 * `mcp:serve` is (opt-in on `mcp.enabled`), plus its own no-op path when
 * `mcp.discover_attributes` is off.
 */
final class McpWarmupCommandTest extends PhpUnitTestCase
{
    private const SANDBOX_MODULES = __DIR__ . '/../../../tests/sandbox/app/Modules';

    #[Before]
    #[After]
    public function resetState(): void
    {
        Config::remove('mcp.enabled');
        Config::remove('mcp.discover_attributes');
        Config::remove('mcp.discovery_cache');
        Config::remove('mcp.module_dirs');
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new McpWarmupCommand());
    }

    public function testDisabledByDefault(): void
    {
        $exitCode = $this->tester()->execute(['--context' => 'web']);

        $this->assertSame(1, $exitCode);
    }

    public function testEnabledButDiscoveryOffIsANoOp(): void
    {
        Config::set('mcp.enabled', true, true);
        Config::set('mcp.discover_attributes', false, true);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('nothing to warm', $tester->getDisplay());
    }

    public function testWarmsTheDiscoveryCacheWhenEnabled(): void
    {
        $cacheDir = sys_get_temp_dir() . '/quiote-mcp-warmup-test-' . uniqid();
        $hadPrevious = Config::has('core.cache_dir');
        $previous = $hadPrevious ? Config::get('core.cache_dir') : null;
        Config::set('mcp.enabled', true, true);
        Config::set('mcp.discover_attributes', true, true);
        Config::set('mcp.module_dirs', [self::SANDBOX_MODULES], true);
        Config::set('core.cache_dir', $cacheDir, true);

        try {
            $tester = $this->tester();
            $exitCode = $tester->execute(['--context' => 'web']);

            $this->assertSame(0, $exitCode, $tester->getDisplay());
            $this->assertStringContainsString('cache warmed', $tester->getDisplay());
        } finally {
            if ($hadPrevious) {
                Config::set('core.cache_dir', $previous, true);
            } else {
                Config::remove('core.cache_dir');
            }
            (new \Symfony\Component\Filesystem\Filesystem())->remove($cacheDir);
        }
    }
}
