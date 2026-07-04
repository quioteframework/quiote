<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\AppDirResolver;
use Quiote\Exception\QuioteException;

/**
 * Covers AppDirResolver's precedence: explicit option > $QUIOTE_*_ env vars >
 * .quiote.json marker file (walked up from cwd) > upward search for
 * Config/settings.*. Each test builds its own scratch directory tree under
 * sys_get_temp_dir() and chdir()s into it -- cwd is process-global state, so
 * this runs in separate processes to avoid leaking a changed cwd into other
 * test classes sharing the suite's process.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class AppDirResolverTest extends TestCase
{
    private string $root;
    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCwd = getcwd();
        $this->root = sys_get_temp_dir() . '/quiote-appdirresolver-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        putenv('QUIOTE_APP_DIR');
        putenv('QUIOTE_ENV');
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        putenv('QUIOTE_APP_DIR');
        putenv('QUIOTE_ENV');
        $this->removeDir($this->root);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function mkapp(string $relativeDir): string
    {
        $appDir = $this->root . '/' . $relativeDir;
        mkdir($appDir . '/Config', 0777, true);
        file_put_contents($appDir . '/Config/settings.php', '<?php return [];');
        return $appDir;
    }

    public function testExplicitOptionWinsOverEverythingElse(): void
    {
        $appDir = $this->mkapp('app');
        chdir($this->root);
        putenv('QUIOTE_APP_DIR=' . $this->root . '/does-not-matter');

        $result = AppDirResolver::resolve($appDir, 'staging');

        $this->assertSame(realpath($appDir), $result['appDir']);
        $this->assertSame('staging', $result['env']);
    }

    public function testExplicitOptionThrowsWhenDirectoryDoesNotExist(): void
    {
        $this->expectException(QuioteException::class);
        AppDirResolver::resolve($this->root . '/nonexistent', null);
    }

    public function testEnvironmentVariableUsedWhenNoOption(): void
    {
        $appDir = $this->mkapp('app');
        putenv('QUIOTE_APP_DIR=' . $appDir);
        putenv('QUIOTE_ENV=staging');

        $result = AppDirResolver::resolve(null, null);

        $this->assertSame(realpath($appDir), $result['appDir']);
        $this->assertSame('staging', $result['env']);
    }

    public function testMarkerFileFoundFromNestedSubdirectory(): void
    {
        $appDir = $this->mkapp('app');
        file_put_contents(
            $this->root . '/.quiote.json',
            json_encode(['app_dir' => 'app', 'env' => 'staging'])
        );
        mkdir($this->root . '/nested/deep', 0777, true);
        chdir($this->root . '/nested/deep');

        $result = AppDirResolver::resolve(null, null);

        $this->assertSame(realpath($appDir), $result['appDir']);
        $this->assertSame('staging', $result['env']);
    }

    public function testMarkerFileAppDirCanBeAbsolute(): void
    {
        $appDir = $this->mkapp('elsewhere/app');
        mkdir($this->root . '/project', 0777, true);
        file_put_contents(
            $this->root . '/project/.quiote.json',
            json_encode(['app_dir' => $appDir])
        );
        chdir($this->root . '/project');

        $result = AppDirResolver::resolve(null, null);

        $this->assertSame(realpath($appDir), $result['appDir']);
    }

    public function testExplicitEnvOptionOverridesMarkerFileEnv(): void
    {
        $appDir = $this->mkapp('app');
        file_put_contents(
            $this->root . '/.quiote.json',
            json_encode(['app_dir' => 'app', 'env' => 'staging'])
        );
        chdir($this->root);

        $result = AppDirResolver::resolve(null, 'production');

        $this->assertSame('production', $result['env']);
    }

    public function testMalformedMarkerFileFallsBackToUpwardSearch(): void
    {
        $appDir = $this->mkapp('.');
        file_put_contents($this->root . '/.quiote.json', 'not valid json{{{');

        chdir($this->root);

        $result = AppDirResolver::resolve(null, null);

        $this->assertSame(realpath($appDir), $result['appDir']);
    }

    public function testMarkerFileWithUnresolvableAppDirFallsBackToUpwardSearch(): void
    {
        $appDir = $this->mkapp('.');
        file_put_contents(
            $this->root . '/.quiote.json',
            json_encode(['app_dir' => 'does-not-exist'])
        );
        chdir($this->root);

        $result = AppDirResolver::resolve(null, null);

        $this->assertSame(realpath($appDir), $result['appDir']);
    }

    public function testUpwardSearchForSettingsFileWhenNoMarkerPresent(): void
    {
        $appDir = $this->mkapp('app');
        mkdir($appDir . '/nested/deep', 0777, true);
        chdir($appDir . '/nested/deep');

        $result = AppDirResolver::resolve(null, null);

        $this->assertSame(realpath($appDir), $result['appDir']);
        $this->assertNull($result['env']);
    }

    public function testReturnsNullAppDirWhenNothingResolves(): void
    {
        chdir($this->root);

        $result = AppDirResolver::resolve(null, null);

        $this->assertNull($result['appDir']);
    }
}
