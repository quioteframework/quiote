<?php

use PHPUnit\Framework\TestCase;
use Quiote\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves `quiote new` end to end: not just "files got written", but that the
 * generated app actually boots and serves real HTTP responses. Runs the
 * generated pub/index.php under PHP's built-in web server in a separate
 * process -- deliberately NOT via Quiote\Context::getInstance() in-process,
 * since that would collide with the sandbox app's own static Config/Context
 * state that the rest of this suite already sets up (see
 * memory: test-suite-apcu-and-order-independence).
 */
final class NewCommandTest extends TestCase
{
    private static string $appDir;
    private static ?int $serverPid = null;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/quiote-new-command-test-' . uniqid();

        $application = new Application();
        $command = $application->find('new');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'path' => self::$appDir,
            '--namespace' => 'DemoApp',
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException('quiote new failed: ' . $tester->getDisplay());
        }

        self::$port = 8000 + random_int(1, 999);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', 'pub'],
            $descriptors,
            $pipes,
            self::$appDir,
        );
        if ($process === false) {
            throw new \RuntimeException('Could not start PHP built-in server for generated app');
        }
        $status = proc_get_status($process);
        self::$serverPid = $status['pid'];

        // Poll until the server accepts connections instead of a blind sleep.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('Generated app server did not start listening in time');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid !== null) {
            posix_kill(self::$serverPid, SIGTERM);
        }
        if (is_dir(self::$appDir)) {
            self::removeDirectory(self::$appDir);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function get(string $path): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, is_string($body) ? $body : ''];
    }

    public function testIndexServesSuccessfully(): void
    {
        [$status, $body] = $this->get('/');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('It works!', $body);
    }

    public function testAboutServesSuccessfully(): void
    {
        [$status, $body] = $this->get('/about');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('About', $body);
    }

    public function testContactServesSuccessfullyViaAttributeRoute(): void
    {
        // /contact isn't declared in AppRouting's hand-written routes -- it
        // only exists via the #[Route] attribute on ContactAction, merged in
        // with AttributeRoutes::mergeInto(). A 200 here proves attribute
        // routing and file-based routing coexist end to end, not just in a
        // unit test against the builder.
        [$status, $body] = $this->get('/contact');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Contact', $body);
    }

    public function testBoomReturnsServerErrorWithoutCrashingTheServer(): void
    {
        [$status] = $this->get('/boom');
        $this->assertSame(500, $status);

        // The server process must survive an unhandled exception in one request.
        [$status2, $body2] = $this->get('/');
        $this->assertSame(200, $status2);
        $this->assertStringContainsString('It works!', $body2);
    }

    /**
     * The generated app ships its own phpstan.neon (level 9) and bootstrap so
     * `phpstan analyse` works out of the box against a fresh scaffold -- prove
     * that guarantee holds, not just that the files exist.
     */
    public function testGeneratedAppPassesPhpstanLevel9(): void
    {
        $phpstanBinary = dirname(__DIR__, 4) . '/vendor/bin/phpstan';
        if (!is_file($phpstanBinary)) {
            $this->markTestSkipped('phpstan binary not found at ' . $phpstanBinary);
        }

        $process = proc_open(
            [$phpstanBinary, 'analyse', '--no-progress'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::$appDir,
        );
        $this->assertNotFalse($process, 'Could not start phpstan against the generated app');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "phpstan found issues in the generated app:\n$stdout\n$stderr");
    }
}
