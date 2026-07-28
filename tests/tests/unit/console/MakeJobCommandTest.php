<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * See MakeActionCommandTest's docblock for why this runs through the real
 * `bin/quiote` CLI in a subprocess rather than an in-process CommandTester.
 */
final class MakeJobCommandTest extends TestCase
{
    use QuioteCliProcessTrait;

    private static string $appDir;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/quiote-make-job-test-' . uniqid();

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

    public function testGeneratesPlainJob(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:job', 'SendWelcomeEmail', '--app-dir', self::$appDir]);

        $this->assertSame(0, $exitCode, $stdout);

        $file = self::$appDir . '/Jobs/SendWelcomeEmailJob.php';
        $this->assertFileExists($file);
        $contents = file_get_contents($file);
        $this->assertIsString($contents);
        $this->assertStringContainsString('namespace DemoApp\\Jobs;', $contents);
        $this->assertStringContainsString('implements Job', $contents);
        $this->assertStringNotContainsString('RetryableJob', $contents);
    }

    public function testGeneratesRetryableJob(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:job', 'ImportOrders', '--app-dir', self::$appDir, '--retryable']);

        $this->assertSame(0, $exitCode, $stdout);

        $contents = file_get_contents(self::$appDir . '/Jobs/ImportOrdersJob.php');
        $this->assertIsString($contents);
        $this->assertStringContainsString('implements RetryableJob', $contents);
        $this->assertStringContainsString('function maxAttempts(): int', $contents);
        $this->assertStringContainsString('function backoffSeconds(int $attempt): int', $contents);
    }

    public function testRejectsInvalidName(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:job', 'not-valid', '--app-dir', self::$appDir]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('is not a valid name', self::collapseWhitespace($stdout));
    }

    public function testDoesNotOverwriteExistingFileWithoutForce(): void
    {
        [$exitCode] = $this->runCli(['make:job', 'Dup', '--app-dir', self::$appDir]);
        $this->assertSame(0, $exitCode);

        [$secondExitCode, $stdout] = $this->runCli(['make:job', 'Dup', '--app-dir', self::$appDir]);
        $this->assertSame(1, $secondExitCode);
        $this->assertStringContainsString('already exists', self::collapseWhitespace($stdout));

        [$forcedExitCode] = $this->runCli(['make:job', 'Dup', '--app-dir', self::$appDir, '--force']);
        $this->assertSame(0, $forcedExitCode);
    }
}
