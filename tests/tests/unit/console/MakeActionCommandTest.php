<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `make:action` extends AbstractAppCommand, which reuses core.app_dir once
 * it's already set -- and tests/bootstrap.php sets it (to the sandbox app)
 * for the whole PHPUnit process, so a --app-dir option passed to an
 * in-process CommandTester would be silently ignored (see AboutCommandTest's
 * docblock for the same constraint). Run through the real `bin/quiote` CLI
 * in a subprocess instead, exactly as a user would, against a throwaway app
 * scaffolded by `new` -- mirrors NewCommandTest's phpstan-subprocess check.
 */
final class MakeActionCommandTest extends TestCase
{
    use QuioteCliProcessTrait;

    private static string $appDir;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/quiote-make-action-test-' . uniqid();

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

    public function testGeneratesActionAndViewWithRequestedMethodsAndOutputTypes(): void
    {
        [$exitCode, $stdout] = $this->runCli([
            'make:action', 'Post',
            '--app-dir', self::$appDir,
            '--methods', 'GET,POST',
            '--output-types', 'html,json',
        ]);

        $this->assertSame(0, $exitCode, $stdout);

        $actionFile = self::$appDir . '/Modules/Default/Actions/PostAction.php';
        $this->assertFileExists($actionFile);
        $action = file_get_contents($actionFile);
        $this->assertIsString($action);
        $this->assertStringContainsString('namespace DemoApp\\Modules\\Default\\Actions;', $action);
        $this->assertStringContainsString('function executeRead(WebRequest $rd): string', $action);
        $this->assertStringContainsString('function executeWrite(WebRequest $rd): string', $action);
        $this->assertStringNotContainsString('function executeUpdate', $action);

        $viewFile = self::$appDir . '/Modules/Default/Views/PostSuccessView.php';
        $this->assertFileExists($viewFile);
        $view = file_get_contents($viewFile);
        $this->assertIsString($view);
        $this->assertStringContainsString('function executeHtml(WebRequest $rd): void', $view);
        $this->assertStringContainsString('function executeJson(WebRequest $rd): string', $view);

        $outputTypes = file_get_contents(self::$appDir . '/Config/output_types.xml');
        $this->assertIsString($outputTypes);
        $this->assertStringContainsString('<output_type name="json">', $outputTypes);
        $this->assertStringContainsString('application/json; charset=UTF-8', $outputTypes);
    }

    public function testRejectsUnknownHttpMethod(): void
    {
        [$exitCode, $stdout] = $this->runCli([
            'make:action', 'Widget',
            '--app-dir', self::$appDir,
            '--methods', 'FOO',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown HTTP method "FOO"', self::collapseWhitespace($stdout));
        $this->assertFileDoesNotExist(self::$appDir . '/Modules/Default/Actions/WidgetAction.php');
    }

    public function testRejectsOutputTypesWithoutView(): void
    {
        [$exitCode, $stdout] = $this->runCli([
            'make:action', 'Widget',
            '--app-dir', self::$appDir,
            '--no-view',
            '--output-types', 'json',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--output-types has no effect with --no-view', self::collapseWhitespace($stdout));
    }

    public function testDoesNotOverwriteExistingFileWithoutForce(): void
    {
        [$exitCode] = $this->runCli(['make:action', 'Dup', '--app-dir', self::$appDir]);
        $this->assertSame(0, $exitCode);

        [$secondExitCode, $stdout] = $this->runCli(['make:action', 'Dup', '--app-dir', self::$appDir]);
        $this->assertSame(1, $secondExitCode);
        $this->assertStringContainsString('already exists', self::collapseWhitespace($stdout));

        [$forcedExitCode] = $this->runCli(['make:action', 'Dup', '--app-dir', self::$appDir, '--force']);
        $this->assertSame(0, $forcedExitCode);
    }
}
