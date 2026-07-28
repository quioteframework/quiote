<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unlike the other `make:*` commands, ServeCommand never calls
 * AbstractAppCommand::bootstrapApp() -- it only needs AppDirResolver, which
 * doesn't consult Config::has('core.app_dir') -- so the failure paths (no
 * app found / no pub dir) can run safely in-process via CommandTester.
 * Deliberately does NOT spawn a real server here: it's a long-running
 * process handed to Symfony Process, and killing only the immediate
 * `bin/quiote serve` PID can orphan the grandchild `php -S`/`frankenphp`
 * process holding the test runner's own stdout pipe open, hanging the
 * whole suite rather than just this test.
 */
final class ServeCommandTest extends TestCase
{
    use QuioteCliProcessTrait;

    public function testFailsWhenNoAppCanBeLocated(): void
    {
        $emptyDir = sys_get_temp_dir() . '/quiote-serve-empty-test-' . uniqid();
        mkdir($emptyDir);

        $application = new Application();
        $tester = new CommandTester($application->find('serve'));
        $exitCode = $tester->execute(['--app-dir' => $emptyDir]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('does not exist', $tester->getDisplay());

        rmdir($emptyDir);
    }

    /**
     * A nonexistent --app-dir makes AppDirResolver throw rather than return
     * a null appDir (unlike the "found a dir but it's not an app" case
     * above) -- CommandTester::execute() doesn't catch that the way the real
     * `bin/quiote` entrypoint's Application::run() does, so this goes
     * through the CLI subprocess instead to exercise the actual user-facing
     * behavior (a clean exit 1, not an uncaught exception).
     */
    public function testFailsWhenAppDirCannotBeResolvedAtAll(): void
    {
        [$exitCode] = $this->runCli(['serve', '--app-dir', '/does/not/exist/at/all']);

        $this->assertSame(1, $exitCode);
    }

    public function testAnUnknownRuntimeIsRejectedAndTheChoicesAreListed(): void
    {
        $appDir = $this->makeAppDirWithPub();

        try {
            $application = new Application();
            $tester = new CommandTester($application->find('serve'));
            $exitCode = $tester->execute(['--app-dir' => $appDir, '--runtime' => 'nginx']);

            $this->assertSame(1, $exitCode);
            $display = $tester->getDisplay();
            $this->assertStringContainsString('Unknown --runtime "nginx"', $display);
            $this->assertStringContainsString('roadrunner', $display);
            $this->assertStringContainsString('swoole', $display);
        } finally {
            $this->removeAppDir($appDir);
        }
    }

    public function testRoadRunnerNeedsAnRrYamlBeforeItWillStart(): void
    {
        $appDir = $this->makeAppDirWithPub();

        try {
            $application = new Application();
            $tester = new CommandTester($application->find('serve'));
            $exitCode = $tester->execute(['--app-dir' => $appDir, '--runtime' => 'roadrunner']);

            // Either the binary is missing or the config is -- both are reported
            // rather than starting a server that cannot work. No server is spawned
            // in either case, which is why this is safe in-process.
            $this->assertSame(1, $exitCode);
            $this->assertMatchesRegularExpression(
                '/(rr binary was not found|\.rr\.yaml" does not exist)/',
                $tester->getDisplay(),
            );
        } finally {
            $this->removeAppDir($appDir);
        }
    }

    public function testSwooleNeedsItsEntrypointOrTheExtensionBeforeItWillStart(): void
    {
        $appDir = $this->makeAppDirWithPub();

        try {
            $application = new Application();
            $tester = new CommandTester($application->find('serve'));
            $exitCode = $tester->execute(['--app-dir' => $appDir, '--runtime' => 'swoole']);

            $this->assertSame(1, $exitCode);
            $this->assertMatchesRegularExpression(
                '/(ext-swoole is not installed|swoole\.php" does not exist)/',
                $tester->getDisplay(),
            );
        } finally {
            $this->removeAppDir($appDir);
        }
    }

    /** An app-shaped directory, enough for ServeCommand to get past locating pub/. */
    private function makeAppDirWithPub(): string
    {
        $appDir = sys_get_temp_dir() . '/quiote-serve-runtime-test-' . uniqid();
        mkdir($appDir . '/pub', 0777, true);

        return $appDir;
    }

    private function removeAppDir(string $appDir): void
    {
        @rmdir($appDir . '/pub');
        @rmdir($appDir);
    }
}
