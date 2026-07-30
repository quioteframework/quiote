<?php

use Quiote\Config\Config;
use Quiote\Console\Application;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `cache:warmup --check` is the CI guard: it re-emits the compiled routing
 * matcher in memory and compares it with what is committed, without writing.
 *
 * These tests turn `core.debug` off around the `--check` runs. The sandbox app
 * runs in debug, where Quiote::bootstrap() deliberately clears the cache
 * directory -- so a `--check` bootstrapping in debug mode would wipe the very
 * artifact it is about to look for and always report it missing. Debug builds
 * never read these caches anyway; the guard is meant for non-debug CI.
 */
final class CacheWarmupDriftCheckTest extends PhpUnitTestCase
{
    private mixed $previousDebug = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDebug = Config::get('core.debug');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::set('core.debug', $this->previousDebug, true, true);
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application())->find('cache:warmup'));
    }

    /** Warms the cache and returns the compiled matcher's path as reported by the command. */
    private function warmAndLocateArtifact(): string
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->execute(['--context' => 'web']));
        preg_match('#-> (\S+CompiledMatcher\S+\.php)#', $tester->getDisplay(), $matches);
        $path = $matches[1] ?? null;
        $this->assertIsString($path, 'warmup should report the compiled matcher path');
        $this->assertFileExists($path, 'warmup should have written the compiled matcher');
        return $path;
    }

    /** Stops bootstrap from clearing the cache dir out from under --check. */
    private function disableDebugSoTheCacheSurvivesBootstrap(): void
    {
        Config::set('core.debug', false, true, true);
    }

    public function testReportsUpToDateRightAfterAWarmup(): void
    {
        $this->warmAndLocateArtifact();
        $this->disableDebugSoTheCacheSurvivesBootstrap();

        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web', '--check' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('up to date', $tester->getDisplay());
    }

    public function testCheckWritesNothing(): void
    {
        $artifact = $this->warmAndLocateArtifact();
        $before = (string) file_get_contents($artifact);
        unlink($artifact);
        $this->disableDebugSoTheCacheSurvivesBootstrap();

        $this->tester()->execute(['--context' => 'web', '--check' => true]);

        // --check is a comparison, not a warmup: it must not put the file back.
        $this->assertFileDoesNotExist($artifact);
        $this->assertNotSame('', $before);
    }

    public function testFailsWhenTheCompiledMatcherIsMissing(): void
    {
        $artifact = $this->warmAndLocateArtifact();
        unlink($artifact);
        $this->disableDebugSoTheCacheSurvivesBootstrap();

        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web', '--check' => true]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('missing', $display);
        // The message has to tell CI how to fix it.
        $this->assertStringContainsString('cache:warmup', $display);
    }

    public function testFailsWhenTheCompiledMatcherIsStale(): void
    {
        $artifact = $this->warmAndLocateArtifact();
        file_put_contents($artifact, "<?php\n// compiled from an older route set\n");
        $this->disableDebugSoTheCacheSurvivesBootstrap();

        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web', '--check' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('stale', $tester->getDisplay());
    }
}
