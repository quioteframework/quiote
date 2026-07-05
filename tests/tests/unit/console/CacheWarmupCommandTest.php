<?php

use Quiote\Console\Application;
use Quiote\Config\Config;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises `cache:warmup` through the CLI harness. The sandbox app is already
 * bootstrapped by PhpUnitTestCase (core.app_dir set), so the command compiles
 * that app's config set into its on-disk cache (file backend under CLI, where
 * APCu is off).
 */
final class CacheWarmupCommandTest extends PhpUnitTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application();
        return new CommandTester($application->find('cache:warmup'));
    }

    public function testWarmsConfigIntoTheFileCache(): void
    {
        $cacheConfigDir = Config::getString('core.cache_dir') . '/config';
        // Start from a cold cache so we prove the command actually compiles.
        array_map('unlink', glob($cacheConfigDir . '/settings*.php') ?: []);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web']);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('backend: file', $display);
        // settings + factories are always present in the sandbox app.
        $this->assertMatchesRegularExpression('/settings\.xml\s+compiled/', $display);
        $this->assertMatchesRegularExpression('/factories\.xml\s+compiled/', $display);

        // The compiled settings cache file now exists on disk.
        $this->assertNotEmpty(glob($cacheConfigDir . '/settings*_web_*.php'), 'expected a compiled settings cache file');
    }

    public function testWarmsCleanlyWithoutErrorLines(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web']);

        $this->assertSame(0, $exitCode);
        // The default warmup set contains only configs that are actually
        // loadable through the config cache, so a healthy app warms with no
        // "error:" rows (regression guard for the dropped vestigial entries
        // compile.xml / routing.xml, which had no handler here).
        $this->assertStringNotContainsString('error:', $tester->getDisplay());
    }
}
