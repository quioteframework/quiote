<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers `quiote new --runtime=...`, which is scaffolding only -- no server is
 * started here. NewCommandTest already proves the generated app boots and
 * serves; this checks the extra entrypoints a CLI-hosted runtime needs.
 */
final class NewCommandRuntimeScaffoldTest extends TestCase
{
    private ?string $appDir = null;

    #[After]
    public function removeGeneratedApp(): void
    {
        if ($this->appDir !== null && is_dir($this->appDir)) {
            self::removeDirectory($this->appDir);
        }
        $this->appDir = null;
    }

    /** @param array<string, string> $extraOptions */
    private function scaffold(array $extraOptions = []): string
    {
        $this->appDir = sys_get_temp_dir() . '/quiote-new-runtime-test-' . uniqid();

        $tester = new CommandTester((new Application())->find('new'));
        $exitCode = $tester->execute([
            'path' => $this->appDir,
            '--namespace' => 'DemoApp',
        ] + $extraOptions);

        $this->assertSame(0, $exitCode, $tester->getDisplay());

        return $this->appDir;
    }

    public function testWithoutARuntimeOnlyTheFrontControllerIsWritten(): void
    {
        $appDir = $this->scaffold();

        // pub/index.php already covers php-fpm, `php -S` and FrankenPHP worker
        // mode, so an app that asked for nothing extra gets nothing extra.
        $this->assertFileExists($appDir . '/pub/index.php');
        $this->assertFileDoesNotExist($appDir . '/worker.php');
        $this->assertFileDoesNotExist($appDir . '/swoole.php');
        $this->assertFileDoesNotExist($appDir . '/.rr.yaml');
    }

    public function testTheFrontControllerNoLongerClaimsToBeFrankenPhpSpecific(): void
    {
        $contents = file_get_contents($this->scaffold() . '/pub/index.php');

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('FrankenPHP worker entrypoint', $contents);
        $this->assertStringContainsString('the runtime is detected', $contents);
    }

    public function testRoadRunnerScaffoldsAWorkerEntrypointOutsideTheDocumentRoot(): void
    {
        $appDir = $this->scaffold(['--runtime' => 'roadrunner']);

        // In the app root, not pub/: a worker entrypoint must not be reachable
        // through the document root.
        $this->assertFileExists($appDir . '/worker.php');
        $this->assertFileDoesNotExist($appDir . '/pub/worker.php');
        $this->assertFileExists($appDir . '/.rr.yaml');
        $this->assertFileExists($appDir . '/pub/index.php');

        $worker = file_get_contents($appDir . '/worker.php');
        $this->assertIsString($worker);
        $this->assertStringContainsString("'worker_runtime' => 'roadrunner'", $worker);
        // Resolved from the app root, so the relative candidates are one level
        // shallower than pub/index.php's.
        $this->assertStringContainsString("__DIR__ . '/vendor/autoload.php'", $worker);
        $this->assertStringContainsString("'app_dir' => __DIR__", $worker);
    }

    public function testTheGeneratedWorkerEntrypointIsValidPhp(): void
    {
        $appDir = $this->scaffold(['--runtime' => 'roadrunner']);

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($appDir . '/worker.php'), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testTheGeneratedRrYamlPointsAtTheWorkerAndLetsTheServerRecycle(): void
    {
        $yaml = file_get_contents($this->scaffold(['--runtime' => 'roadrunner']) . '/.rr.yaml');

        $this->assertIsString($yaml);
        $this->assertStringContainsString('command: "php worker.php"', $yaml);
        // Recycling belongs to the server: a PHP-side stop mid-pool looks like a
        // crashed worker to RoadRunner.
        $this->assertStringContainsString('max_jobs:', $yaml);

        $parsed = Symfony\Component\Yaml\Yaml::parse($yaml);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('http', $parsed);
    }

    public function testSwooleScaffoldsItsOwnEntrypointAndNoRrConfig(): void
    {
        $appDir = $this->scaffold(['--runtime' => 'swoole']);

        $this->assertFileExists($appDir . '/swoole.php');
        $this->assertFileDoesNotExist($appDir . '/worker.php');
        $this->assertFileDoesNotExist($appDir . '/.rr.yaml');

        $entrypoint = file_get_contents($appDir . '/swoole.php');
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString("'worker_runtime' => 'swoole'", $entrypoint);

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($appDir . '/swoole.php'), $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testAnUnknownRuntimeIsRejectedBeforeAnythingIsWritten(): void
    {
        $appDir = sys_get_temp_dir() . '/quiote-new-runtime-reject-' . uniqid();

        $tester = new CommandTester((new Application())->find('new'));
        $exitCode = $tester->execute([
            'path' => $appDir,
            '--namespace' => 'DemoApp',
            '--runtime' => 'nginx',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown --runtime "nginx"', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($appDir);
    }

    public function testFrankenPhpIsRejectedBecauseItNeedsNoExtraEntrypoint(): void
    {
        $appDir = sys_get_temp_dir() . '/quiote-new-runtime-reject-' . uniqid();

        $tester = new CommandTester((new Application())->find('new'));
        $exitCode = $tester->execute([
            'path' => $appDir,
            '--namespace' => 'DemoApp',
            '--runtime' => 'frankenphp',
        ]);

        $this->assertSame(1, $exitCode);
        // Asserted on a short fragment: SymfonyStyle word-wraps the error block,
        // so a longer phrase gets newlines injected mid-sentence.
        $this->assertStringContainsString('Unknown --runtime "frankenphp"', $tester->getDisplay());
        $this->assertStringContainsString('need no extra entrypoint', $tester->getDisplay());
    }

    private static function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
