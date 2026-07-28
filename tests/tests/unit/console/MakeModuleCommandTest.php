<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * See MakeActionCommandTest's docblock for why this runs through the real
 * `bin/quiote` CLI in a subprocess rather than an in-process CommandTester.
 */
final class MakeModuleCommandTest extends TestCase
{
    use QuioteCliProcessTrait;

    private static string $appDir;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/quiote-make-module-test-' . uniqid();

        $application = new Application();
        $tester = new CommandTester($application->find('new'));
        $exitCode = $tester->execute([
            'path' => self::$appDir,
            '--namespace' => 'DemoApp',
        ]);
        if ($exitCode !== 0) {
            throw new \RuntimeException('quiote new failed: ' . $tester->getDisplay());
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::removeDirectory(self::$appDir);
    }

    public function testCreatesEmptyModuleDirectories(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:module', 'Blog', '--app-dir', self::$appDir]);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertDirectoryExists(self::$appDir . '/Modules/Blog/Actions');
        $this->assertDirectoryExists(self::$appDir . '/Modules/Blog/Views');
        $this->assertDirectoryExists(self::$appDir . '/Modules/Blog/Templates');
        $this->assertFileDoesNotExist(self::$appDir . '/Modules/Blog/Actions/IndexAction.php');
    }

    public function testWithIndexSeedsAnIndexActionTrio(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:module', 'Shop', '--app-dir', self::$appDir, '--with-index']);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertFileExists(self::$appDir . '/Modules/Shop/Actions/IndexAction.php');
        $this->assertFileExists(self::$appDir . '/Modules/Shop/Views/IndexSuccessView.php');
    }

    public function testRejectsInvalidModuleName(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:module', 'not-valid', '--app-dir', self::$appDir]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('is not a valid name', self::collapseWhitespace($stdout));
        $this->assertDirectoryDoesNotExist(self::$appDir . '/Modules/not-valid');
    }
}
