<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real, Docker-based end-to-end verification that telemetry actually flows:
 * a real OTel Collector (OTLP HTTP receiver + file exporter, so this test can
 * read exactly what it received) and the real sample app served by real
 * FrankenPHP in worker mode with `telemetry.export.mode = batch` — the
 * production deployment shape (see the repo root Dockerfile/Caddyfile/
 * docker-compose.yml), not a simulated stand-in.
 *
 * This is the automated, repeatable version of the manual exercise recorded
 * in docs/OPENTELEMETRY_E2E_VERIFICATION.md — including a regression check
 * for the `Trace::current()` lifecycle bug that exercise found (see
 * `Quiote\Telemetry\OtelSpanHandle`'s class docblock), now verified under the
 * real worker/batch-mode conditions the bug actually depends on, not just
 * PHPUnit's in-process simulation of them.
 *
 * #[Group('e2e')]: excluded from the default `composer test` run (see
 * tests/config/phpunit.xml) — these tests need Docker and take real wall-clock
 * time to build/start/tear down containers. Run explicitly with
 * `composer test:e2e`.
 */
#[Group('e2e')]
class OtelCollectorE2ETest extends TestCase
{
    private const APP_URL = 'http://127.0.0.1:8180';
    private const CONTAINER_NAME = 'e2e-quiote-app-1';

    private static string $composeFile;
    private static string $outputDir;
    private static string $outputFile;

    public static function setUpBeforeClass(): void
    {
        self::$composeFile = __DIR__ . '/docker-compose.yml';
        self::$outputDir = __DIR__ . '/otel-output';
        self::$outputFile = self::$outputDir . '/output.json';

        self::compose('down -v');
        self::resetOutputDir();
        self::compose('up -d --build');
        self::waitForHealthy();
    }

    public static function tearDownAfterClass(): void
    {
        self::compose('down -v');
        self::removeOutputDir();
    }

    // --- infrastructure lifecycle -----------------------------------------------

    private static function compose(string $args): void
    {
        $cmd = 'docker compose -f ' . escapeshellarg(self::$composeFile) . ' ' . $args . ' 2>&1';
        exec($cmd, $output, $exit);
        if ($exit !== 0) {
            self::fail("docker compose $args failed (exit $exit):\n" . implode("\n", $output));
        }
    }

    private static function resetOutputDir(): void
    {
        self::removeOutputDir();
        mkdir(self::$outputDir, 0777, true);
        // The collector container runs as a non-root user; the bind mount
        // must be world-writable or its file exporter fails to open its
        // output file at all (confirmed: this exact failure was hit and
        // fixed while building this test).
        chmod(self::$outputDir, 0777);
    }

    private static function removeOutputDir(): void
    {
        if (!is_dir(self::$outputDir)) {
            return;
        }
        foreach (scandir(self::$outputDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink(self::$outputDir . '/' . $entry);
        }
        @rmdir(self::$outputDir);
    }

    private static function waitForHealthy(int $timeoutSeconds = 90): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            exec('docker inspect ' . escapeshellarg(self::CONTAINER_NAME) . ' --format "{{.State.Health.Status}}" 2>&1', $output, $exit);
            $status = $output[0] ?? '';
            $output = [];
            if ($exit === 0 && $status === 'healthy') {
                return;
            }
            sleep(2);
        }
        self::fail(self::CONTAINER_NAME . ' did not become healthy within ' . $timeoutSeconds . 's');
    }

    // --- HTTP + telemetry-file helpers -------------------------------------------

    /** @return array{status: int, body: string} */
    private function request(string $path): array
    {
        $ch = curl_init(self::APP_URL . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $body = curl_exec($ch);
        if ($body === false) {
            $this->fail('curl request to ' . $path . ' failed: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['status' => $status, 'body' => (string) $body];
    }

    /** @return list<string> */
    private function readLines(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $lines = file($file);
        return $lines === false ? [] : $lines;
    }

    /**
     * Fires a request against $path and returns only the trace/metric export
     * batches that appeared in the collector's output file AFTER that
     * request — sidesteps ambiguity with the docker-compose healthcheck's own
     * periodic `GET /` hits (which would otherwise share a span name with a
     * real test request to `/`).
     * @return array{status:int, body:string, spans:list<array>, metricNames:list<string>}
     */
    private function requestAndCollectNewTelemetry(string $path, int $waitSeconds = 15): array
    {
        $linesBefore = count($this->readLines(self::$outputFile));

        $response = $this->request($path);

        $deadline = microtime(true) + $waitSeconds;
        $newLines = [];
        while (microtime(true) < $deadline) {
            $allLines = $this->readLines(self::$outputFile);
            if (count($allLines) > $linesBefore) {
                // Give the batch a moment to settle in case multiple
                // export calls land back-to-back (traces then metrics).
                usleep(500_000);
                $settled = $this->readLines(self::$outputFile);
                $newLines = array_slice($settled, $linesBefore);
                break;
            }
            usleep(200_000);
        }

        $spans = [];
        $metricNames = [];
        foreach ($newLines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded['resourceSpans'] ?? [] as $rs) {
                $resourceAttrs = $this->flattenAttributes($rs['resource']['attributes'] ?? []);
                foreach ($rs['scopeSpans'] ?? [] as $ss) {
                    foreach ($ss['spans'] ?? [] as $span) {
                        $span['_resource'] = $resourceAttrs;
                        $span['_attrs'] = $this->flattenAttributes($span['attributes'] ?? []);
                        $spans[] = $span;
                    }
                }
            }
            foreach ($decoded['resourceMetrics'] ?? [] as $rm) {
                foreach ($rm['scopeMetrics'] ?? [] as $sm) {
                    foreach ($sm['metrics'] ?? [] as $metric) {
                        $metricNames[] = $metric['name'];
                    }
                }
            }
        }

        return ['status' => $response['status'], 'body' => $response['body'], 'spans' => $spans, 'metricNames' => $metricNames];
    }

    /**
     * @param array<int,array{key:string,value:array<string,mixed>}> $attrs
     * @return array<string, mixed>
     */
    private function flattenAttributes(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $attr) {
            $value = $attr['value'];
            $out[$attr['key']] = $value['stringValue']
                ?? $value['boolValue']
                ?? $value['intValue']
                ?? $value['doubleValue']
                ?? null;
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $spans
     * @return ?array<string, mixed>
     */
    private function findSpanByName(array $spans, string $name): ?array
    {
        foreach ($spans as $span) {
            if (($span['name'] ?? null) === $name) {
                return $span;
            }
        }
        return null;
    }

    // --- tests -----------------------------------------------------------------

    public function testHomeRequestProducesTheFullNestedSpanTree(): void
    {
        $result = $this->requestAndCollectNewTelemetry('/');
        $this->assertSame(200, $result['status']);

        $spans = $result['spans'];
        $root = $this->findSpanByName($spans, 'GET /');
        $match = $this->findSpanByName($spans, 'match');
        $action = $this->findSpanByName($spans, 'Default:Index');
        $view = $this->findSpanByName($spans, 'Default:IndexSuccess');

        $this->assertNotNull($root, 'root span missing');
        $this->assertNotNull($match, 'route-match span missing');
        $this->assertNotNull($action, 'action span missing');
        $this->assertNotNull($view, 'view span missing');

        // Real resource detection ran for real -- not a hardcoded fixture.
        $this->assertSame('quiote-e2e-frankenphp', $root['_resource']['service.name']);
        $this->assertSame('opentelemetry', $root['_resource']['telemetry.sdk.name']);
        $this->assertSame('php', $root['_resource']['telemetry.sdk.language']);

        $traceId = $root['traceId'];
        $this->assertSame($traceId, $match['traceId']);
        $this->assertSame($traceId, $action['traceId']);
        $this->assertSame($traceId, $view['traceId']);

        // Correct nesting, not a flat list of same-trace spans.
        $this->assertSame($root['spanId'], $match['parentSpanId']);
        $this->assertSame($root['spanId'], $action['parentSpanId']);
        $this->assertSame($action['spanId'], $view['parentSpanId']);
    }

    public function testAboutRequestRenamesTheRootSpanToTheRouteTemplate(): void
    {
        $result = $this->requestAndCollectNewTelemetry('/about');
        $this->assertSame(200, $result['status']);

        $root = $this->findSpanByName($result['spans'], 'GET /about');
        $this->assertNotNull($root, 'RoutingMiddleware must rename the root span to "GET {route}" once a route matches');
        $this->assertSame('/about', $root['_attrs']['http.route']);
        $this->assertSame('about', $root['_attrs']['route_name']);
    }

    public function testBoomRequestRecordsErrorStatusOnTheRootSpanUnderRealWorkerMode(): void
    {
        // The regression test for the Trace::current() lifecycle bug
        // (docs/OPENTELEMETRY_E2E_VERIFICATION.md) -- run here under the
        // REAL conditions that bug depended on: real FrankenPHP worker mode,
        // real BatchSpanProcessor, a real RoutingMiddleware borrowing
        // Trace::current() before the exception unwinds back through it.
        // TelemetryBootstrapTest's in-process regression tests cover the
        // mechanism directly; this proves the fix holds in the actual
        // deployment shape, not just PHPUnit's simulation of it.
        $result = $this->requestAndCollectNewTelemetry('/boom');
        $this->assertSame(500, $result['status']);

        $root = $this->findSpanByName($result['spans'], 'GET /boom');
        $action = $this->findSpanByName($result['spans'], 'Default:Boom');

        $this->assertNotNull($root);
        $this->assertNotNull($action);

        // OTLP JSON status code 2 == STATUS_CODE_ERROR.
        $this->assertSame(2, $root['status']['code'] ?? null, 'root span must carry Error status, not Unset');
        $this->assertStringContainsString('Boom!', $root['status']['message'] ?? '');
        $this->assertSame(2, $action['status']['code'] ?? null, 'action span must also carry Error status');
    }

    public function testFourOhFourIsNotTreatedAsASpanError(): void
    {
        $result = $this->requestAndCollectNewTelemetry('/this-route-does-not-exist');
        $this->assertSame(404, $result['status']);

        $root = $this->findSpanByName($result['spans'], 'GET /this-route-does-not-exist');
        $this->assertNotNull($root);
        $this->assertNotSame(2, $root['status']['code'] ?? null, 'a 404 is an expected outcome, not a span-level error');
    }

    public function testMetricsAreExportedAlongsideTraces(): void
    {
        $result = $this->requestAndCollectNewTelemetry('/');

        $this->assertContains('http.server.request.duration', $result['metricNames']);
        $this->assertContains('quiote.request.cpu.time', $result['metricNames']);
        $this->assertContains('quiote.request.memory.peak', $result['metricNames']);
        $this->assertContains('quiote.worker.memory.rss', $result['metricNames']);
        $this->assertContains('http.server.request.count', $result['metricNames']);
    }
}
