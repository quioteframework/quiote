<?php

/**
 * Shared helper for console command tests that must exercise the real
 * `bin/quiote` CLI in a subprocess rather than via an in-process
 * CommandTester -- required for any AbstractAppCommand subclass, since
 * tests/bootstrap.php sets core.app_dir once for the whole PHPUnit process
 * and AbstractAppCommand::bootstrapApp() reuses it whenever it's already
 * set, silently ignoring a test's --app-dir option (see AboutCommandTest's
 * docblock for the same constraint).
 */
trait QuioteCliProcessTrait
{
    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args): array
    {
        $binary = dirname(__DIR__, 2) . '/bin/quiote';
        $process = proc_open(
            [PHP_BINARY, $binary, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException('Could not start bin/quiote subprocess');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
    }

    /** SymfonyStyle word-wraps its boxed output, so assertions on a message spanning a wrap point need whitespace collapsed first. */
    private static function collapseWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
