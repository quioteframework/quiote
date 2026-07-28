<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * See MakeActionCommandTest's docblock for why this runs through the real
 * `bin/quiote` CLI in a subprocess rather than an in-process CommandTester.
 */
final class MakeMiddlewareCommandTest extends TestCase
{
    use QuioteCliProcessTrait;

    private static string $appDir;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/quiote-make-middleware-test-' . uniqid();

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

    public function testGeneratesMiddlewareWithRequestedAttributeOptions(): void
    {
        [$exitCode, $stdout] = $this->runCli([
            'make:middleware', 'RequestId',
            '--app-dir', self::$appDir,
            '--phase', 'before_action',
            '--priority', '10',
            '--after', 'RoutingMiddleware',
            '--before', 'DispatchMiddleware',
        ]);

        $this->assertSame(0, $exitCode, $stdout);

        $file = self::$appDir . '/Middleware/RequestIdMiddleware.php';
        $this->assertFileExists($file);
        $contents = file_get_contents($file);
        $this->assertIsString($contents);
        $this->assertStringContainsString('namespace DemoApp\\Middleware;', $contents);
        $this->assertStringContainsString("#[Middleware(phase: 'before_action', priority: 10, after: 'RoutingMiddleware', before: 'DispatchMiddleware')]", $contents);
        $this->assertStringContainsString('implements MiddlewareInterface', $contents);
    }

    public function testRejectsUnknownPhase(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:middleware', 'Bogus', '--app-dir', self::$appDir, '--phase', 'nonsense']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown middleware phase "nonsense"', self::collapseWhitespace($stdout));
        $this->assertFileDoesNotExist(self::$appDir . '/Middleware/BogusMiddleware.php');
    }

    public function testRejectsNonIntegerPriority(): void
    {
        [$exitCode, $stdout] = $this->runCli(['make:middleware', 'Bogus', '--app-dir', self::$appDir, '--priority', 'nope']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('is not a valid --priority', self::collapseWhitespace($stdout));
    }
}
