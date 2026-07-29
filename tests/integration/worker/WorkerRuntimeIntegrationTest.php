<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real, Docker-based verification that the RoadRunner and Swoole runtimes work
 * against the actual servers, not a stand-in for them.
 *
 * The unit suites cover the request converters and the loop wiring in isolation,
 * which cannot catch the things that only appear once a real server is on the
 * other end: whether the CGI server params the app reads are actually present,
 * whether a session cookie makes it back to the client, whether repeated
 * Set-Cookie headers survive, whether a stray echo corrupts the protocol stream,
 * and whether the worker is still alive after a request has failed.
 *
 * Every assertion runs against ALL THREE runtimes from the same data provider,
 * so they cannot silently diverge. FrankenPHP matters most -- it is what this
 * framework mainly targets in production -- and it is also the odd one out:
 * it reports itself as a SAPI, so header() works, PHP emits the session cookie
 * itself, and NativeSessionCookieBridge is deliberately skipped. Nothing proven
 * against RoadRunner or Swoole transfers to it for free.
 *
 * #[Group('integration')]: excluded from the default `composer test` run --
 * needs Docker and real wall-clock time to build and boot containers. Run with
 * `composer test:integration`.
 */
#[Group('integration')]
final class WorkerRuntimeIntegrationTest extends TestCase
{
    private const URLS = [
        'roadrunner' => 'http://127.0.0.1:8281',
        'swoole' => 'http://127.0.0.1:8282',
        // The runtime this framework mainly targets in production, and the only
        // one of the three that reports itself as a SAPI -- so it takes a
        // different cookie and output path from the other two and cannot be
        // assumed equivalent to them.
        'frankenphp' => 'http://127.0.0.1:8283',
    ];

    private static string $composeFile;
    private static bool $started = false;

    public static function setUpBeforeClass(): void
    {
        self::$composeFile = __DIR__ . '/docker-compose.yml';

        if (!self::dockerAvailable()) {
            return;
        }

        self::compose('down -v');
        self::compose('up -d --build');
        self::$started = true;
        self::waitForAllRuntimes();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$started) {
            self::compose('down -v');
            self::$started = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::dockerAvailable()) {
            $this->markTestSkipped('Docker is not available for integration tests');
        }
    }

    /**
     * Whether a runtime reports itself as a real SAPI
     * ({@see \Quiote\Runtime\Worker\WorkerRuntimeCapabilities::sapi()}).
     *
     * On a SAPI the loop skips superglobal hydration, because PHP already
     * refilled $_SERVER/$_GET/... itself -- and WebRequest::startup() then
     * clears $_GET/$_POST/$_COOKIE/$_FILES and strips HTTP_* from $_SERVER, the
     * register-globals-era input defence that also applies under php-fpm.
     * Empty superglobals there are correct behaviour, not a missing bridge, so
     * the hydration assertions below only apply to the CLI-hosted runtimes.
     */
    private static function populatesSuperglobalsItself(string $runtime): bool
    {
        return $runtime === 'frankenphp';
    }

    /** @return array<string, array{0: string}> */
    public static function runtimes(): array
    {
        return [
            'roadrunner' => ['roadrunner'],
            'swoole' => ['swoole'],
            'frankenphp' => ['frankenphp'],
        ];
    }

    #[DataProvider('runtimes')]
    public function testTheAppServesASuccessfulResponse(string $runtime): void
    {
        [$status, $body] = self::get($runtime, '/');

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true], self::json($body));
    }

    #[DataProvider('runtimes')]
    public function testTheRuntimeActuallyHostingTheProcessIsTheExpectedOne(string $runtime): void
    {
        $echo = self::json(self::get($runtime, '/echoback')[1]);

        // Guards against the whole suite silently passing against a SAPI fallback.
        $this->assertSame($runtime, $echo['runtime']);
    }

    #[DataProvider('runtimes')]
    public function testTheRequestReachesTheAppIntact(string $runtime): void
    {
        [$status, $body] = self::get($runtime, '/echoback?q=hello&n=2', ['X-Probe: probe-value']);
        $echo = self::json($body);

        $this->assertSame(200, $status);
        $this->assertSame('GET', $echo['method']);
        $this->assertSame('/echoback', $echo['path']);
        $this->assertSame('1.1', $echo['protocol']);
        $this->assertSame('http', $echo['scheme']);
    }

    #[DataProvider('runtimes')]
    public function testTheSuperglobalsLegacyCodeReadsAreHydrated(string $runtime): void
    {
        $echo = self::json(self::get($runtime, '/echoback?q=hello', ['X-Probe: v'])[1]);

        $server = self::subMap($echo, 'server');
        $this->assertSame('GET', $server['REQUEST_METHOD']);
        if (self::populatesSuperglobalsItself($runtime)) {
            // See populatesSuperglobalsItself(): the framework deliberately
            // clears request input here, so there is nothing further to assert
            // beyond the entries WebRequest::startup() leaves alone.
            $this->assertNotNull($server['SCRIPT_NAME']);
            $this->assertTrue($server['REQUEST_TIME_FLOAT_IS_SET']);

            return;
        }
        // Routing generates URLs off SCRIPT_NAME, and neither RoadRunner nor
        // Swoole supplies one, so it has to be synthesised.
        $this->assertNotNull($server['SCRIPT_NAME']);
        $this->assertNotSame('', $server['SCRIPT_NAME']);
        // Request headers reach $_SERVER in CGI form.
        $this->assertSame('v', $server['HTTP_X_PROBE']);
        // TelemetryMiddleware measures wall time from this.
        $this->assertTrue($server['REQUEST_TIME_FLOAT_IS_SET']);
        // The assertion that matters most: without SuperglobalBridge every legacy
        // reader in Routing/Storage/ActionExecutor sees nothing here.
        $this->assertSame(['q' => 'hello'], $echo['get']);
    }

    #[DataProvider('runtimes')]
    public function testAFormPostArrivesAsBothRawBodyAndParsedBody(string $runtime): void
    {
        [$status, $body] = self::post($runtime, '/echoback', 'field=value&other=2', 'application/x-www-form-urlencoded');
        $echo = self::json($body);

        $this->assertSame(200, $status);
        $this->assertSame('POST', $echo['method']);
        if (self::populatesSuperglobalsItself($runtime)) {
            return;
        }
        // CONTENT_TYPE, not HTTP_CONTENT_TYPE: WebRequest reads the bare CGI name.
        $contentType = self::subMap($echo, 'server')['CONTENT_TYPE'] ?? null;
        $this->assertIsString($contentType);
        $this->assertStringContainsString('form-urlencoded', $contentType);
        $this->assertSame(['field' => 'value', 'other' => '2'], $echo['post']);
    }

    #[DataProvider('runtimes')]
    public function testAJsonBodyIsLeftForTheParsingMiddleware(string $runtime): void
    {
        $echo = self::json(self::post($runtime, '/echoback', '{"k":"v"}', 'application/json')[1]);

        $this->assertSame('{"k":"v"}', $echo['body']);
    }

    #[DataProvider('runtimes')]
    public function testProxyHeadersReachTheUrlTheAppWouldGenerate(string $runtime): void
    {
        $echo = self::json(self::get($runtime, '/echoback', [
            'X-Forwarded-Proto: https',
            'X-Forwarded-Host: public.example',
        ])[1]);

        // The correction is applied by WorkerRequestFactory for every runtime, so
        // links an app generates behind a TLS-terminating proxy are correct.
        $this->assertSame('https', $echo['scheme']);
        $this->assertSame('public.example', $echo['host']);
    }

    #[DataProvider('runtimes')]
    public function testALegacySessionIsEstablishedAndThenRecognised(string $runtime): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'quiote-worker-cookies');
        self::assertIsString($jar);

        try {
            [$firstStatus, $firstBody] = self::get($runtime, '/session', [], $jar);
            $this->assertSame(200, $firstStatus);
            $this->assertSame(1, self::json($firstBody)['hits']);

            // The second request carries the cookie from the first. Without
            // NativeSessionCookieBridge no cookie is ever sent off-SAPI, so this
            // would come back as 1 again -- the exact shape of "login silently
            // never works".
            $this->assertSame(2, self::json(self::get($runtime, '/session', [], $jar)[1])['hits']);
            $this->assertSame(3, self::json(self::get($runtime, '/session', [], $jar)[1])['hits']);
        } finally {
            @unlink($jar);
        }
    }

    #[DataProvider('runtimes')]
    public function testACookielessClientGetsItsOwnSessionRatherThanSharingOne(string $runtime): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'quiote-worker-cookies');
        self::assertIsString($jar);

        try {
            self::get($runtime, '/session', [], $jar);
            self::get($runtime, '/session', [], $jar);

            // No cookie jar: a fresh visitor must not inherit the worker's last
            // session, which is the failure mode when session state leaks across
            // requests in a persistent process.
            $this->assertSame(1, self::json(self::get($runtime, '/session')[1])['hits']);
        } finally {
            @unlink($jar);
        }
    }

    /**
     * The end-to-end form of the defect the unit suites cannot reach.
     *
     * At login the user is authenticated (written eagerly) and granted a role
     * (written only at the request boundary). That boundary used to run from
     * Context::reset(), *after* the response had been emitted, where
     * SessionStorage::store()'s lazy @session_start() fails silently because
     * headers are already sent. The next request then found
     * authenticated=true with no roles and no credentials -- an authenticated
     * user who is denied everything, i.e. a permanent 403.
     *
     * It needs a real server and more than one request in the same worker
     * process to reproduce, which is exactly what this harness provides.
     */
    #[DataProvider('runtimes')]
    public function testRolesAndCredentialsGrantedAtLoginSurviveLaterRequests(string $runtime): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'quiote-worker-identity');
        self::assertIsString($jar);

        try {
            $login = self::json(self::get($runtime, '/login', [], $jar)[1]);
            $this->assertTrue($login['authenticated'], 'precondition: the login request itself authenticates');
            $this->assertSame(['administrator'], $login['roles']);

            // Every following request re-reads the user from the session. Three
            // of them, because which worker serves each one varies.
            foreach ([1, 2, 3] as $attempt) {
                $seen = self::json(self::get($runtime, '/identity', [], $jar)[1]);

                $this->assertTrue($seen['authenticated'], "request $attempt lost authentication");
                $this->assertSame(['administrator'], $seen['roles'], "request $attempt lost its roles");
                $this->assertContains(
                    'probe.admin',
                    $seen['credentials'],
                    "request $attempt is authenticated but has no credentials -- the 403 loop",
                );
                $this->assertSame('Ada', $seen['display_name'], "request $attempt lost its user attributes");
            }
        } finally {
            @unlink($jar);
        }
    }

    /**
     * Logging out must invalidate the session id itself, not merely record
     * authenticated=false: the pre-logout id used to stay valid and replayable.
     */
    #[DataProvider('runtimes')]
    public function testLogoutInvalidatesTheSessionForLaterRequests(string $runtime): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'quiote-worker-identity');
        self::assertIsString($jar);

        try {
            self::get($runtime, '/login', [], $jar);
            self::get($runtime, '/logout', [], $jar);

            $after = self::json(self::get($runtime, '/identity', [], $jar)[1]);

            $this->assertFalse($after['authenticated']);
            $this->assertSame([], $after['roles']);
            $this->assertSame([], $after['credentials']);
        } finally {
            @unlink($jar);
        }
    }

    /**
     * A request that touches nothing must cost nothing. Every request that
     * reached the framework used to leave a session row behind and hand out a
     * cookie, because the user hierarchy wrote its empty state unconditionally
     * at the request boundary -- health checks and bot traffic included. That
     * write volume is also what made the SQLite lock contention fire.
     */
    #[DataProvider('runtimes')]
    public function testARequestThatTouchesNothingGetsNoSessionCookie(string $runtime): void
    {
        foreach ([1, 2, 3] as $attempt) {
            [$status, $body, $headers] = self::request($runtime, '/quiet');

            $this->assertSame(200, $status);
            $this->assertTrue(self::json($body)['quiet']);

            $sessionCookies = array_values(array_filter(
                $headers,
                static fn(string $line): bool => stripos($line, 'set-cookie: WORKERPROBE=') === 0,
            ));
            $this->assertSame(
                [],
                $sessionCookies,
                "request $attempt created a session nobody asked for: " . implode(' | ', $headers),
            );
        }
    }

    /**
     * The counterpart, and the case that must keep working: an anonymous
     * visitor who actually writes something does get a session, and it sticks.
     * Setting a preference is a deliberate write, so this is unaffected by the
     * dirty tracking that silences the case above.
     */
    #[DataProvider('runtimes')]
    public function testAnAnonymousVisitorThatWritesStillGetsAPersistentSession(string $runtime): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'quiote-worker-anon');
        self::assertIsString($jar);

        try {
            [, , $headers] = self::request($runtime, '/session', [], null, null, $jar);
            $this->assertNotSame(
                [],
                array_values(array_filter(
                    $headers,
                    static fn(string $line): bool => stripos($line, 'set-cookie: WORKERPROBE=') === 0,
                )),
                'a deliberate session write must still produce a cookie',
            );

            $this->assertSame(2, self::json(self::get($runtime, '/session', [], $jar)[1])['hits']);
        } finally {
            @unlink($jar);
        }
    }

    #[DataProvider('runtimes')]
    public function testRepeatedSetCookieHeadersAllReachTheClient(string $runtime): void
    {
        [$status, , $headers] = self::request($runtime, '/cookies');

        $this->assertSame(200, $status);
        // The session cookie rides along on every response, so only the two this
        // endpoint sets are counted.
        $cookies = array_values(array_filter(
            $headers,
            static fn(string $line): bool => stripos($line, 'set-cookie: first=') === 0
                || stripos($line, 'set-cookie: second=') === 0,
        ));
        $this->assertCount(2, $cookies, 'both cookies must survive: ' . implode(' | ', $headers));
    }

    #[DataProvider('runtimes')]
    public function testStrayOutputEndsUpInTheBodyInsteadOfTheProtocolStream(string $runtime): void
    {
        [$status, $body] = self::get($runtime, '/stray');

        // If capture were missing, the echo would go to the server's own stdout:
        // under RoadRunner that is the relay, so the response would be malformed
        // rather than merely missing the text.
        $this->assertSame(200, $status);
        $this->assertStringContainsString('STRAY-OUTPUT', $body);
        $this->assertStringContainsString('"ok"', $body);
    }

    #[DataProvider('runtimes')]
    public function testAnSseEndpointStreamsEveryEvent(string $runtime): void
    {
        [$status, $body, $headers] = self::request($runtime, '/stream');

        $this->assertSame(200, $status);
        $this->assertTrue(
            (bool) array_filter($headers, static fn(string $h): bool => stripos($h, 'text/event-stream') !== false),
            'expected a text/event-stream content type, got: ' . implode(' | ', $headers),
        );
        foreach (['tick-1', 'tick-2', 'tick-3'] as $expected) {
            $this->assertStringContainsString($expected, $body);
        }
    }

    #[DataProvider('runtimes')]
    public function testAFailedRequestReturnsFiveHundredWithoutTakingTheWorkerDown(string $runtime): void
    {
        $this->assertSame(500, self::get($runtime, '/boom')[0]);

        // Same worker pool, immediately afterwards: a thrown action must not cost
        // the pool a process.
        $this->assertSame(200, self::get($runtime, '/')[0]);
    }

    #[DataProvider('runtimes')]
    public function testStateDoesNotBleedAcrossManySequentialRequests(string $runtime): void
    {
        $expectSuperglobals = !self::populatesSuperglobalsItself($runtime);

        for ($i = 0; $i < 25; $i++) {
            [$status, $body] = self::get($runtime, '/echoback?i=' . $i);
            $this->assertSame(200, $status);
            $echo = self::json($body);
            $this->assertSame('/echoback', $echo['path'], 'request ' . $i . ' saw the wrong path');
            if ($expectSuperglobals) {
                // A superglobal left over from an earlier request would show up here.
                $this->assertSame(['i' => (string) $i], $echo['get']);
            }
        }
    }

    // --- HTTP helpers ------------------------------------------------------------

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private static function get(string $runtime, string $path, array $headers = [], ?string $cookieJar = null): array
    {
        [$status, $body] = self::request($runtime, $path, $headers, null, null, $cookieJar);

        return [$status, $body];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function post(string $runtime, string $path, string $body, string $contentType): array
    {
        [$status, $responseBody] = self::request($runtime, $path, [], $body, $contentType);

        return [$status, $responseBody];
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private static function request(
        string $runtime,
        string $path,
        array $headers = [],
        ?string $body = null,
        ?string $contentType = null,
        ?string $cookieJar = null,
    ): array {
        $ch = curl_init(self::URLS[$runtime] . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($_ch, string $line) use (&$responseHeaders): int {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $responseHeaders[] = $trimmed;
            }
            return strlen($line);
        });

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: ' . ($contentType !== null && $contentType !== '' ? $contentType : 'application/octet-stream');
        }
        if ($headers !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($cookieJar !== null && $cookieJar !== '') {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }

        $responseBody = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        // No curl_close(): deprecated in PHP 8.5 and a no-op since 8.0. Releasing
        // the handle is what flushes the cookie jar, so it is unset explicitly
        // rather than left to fall out of scope.
        unset($ch);

        if (!is_string($responseBody)) {
            self::fail(sprintf('request to %s%s failed: %s', $runtime, $path, $error));
        }

        return [(int) $status, $responseBody, $responseHeaders];
    }

    /**
     * A nested object out of the echoback payload, typed so assertions on its
     * keys don't reduce to mixed.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function subMap(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            self::fail(sprintf('expected "%s" to be an object in the echoback payload', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<string, mixed> */
    private static function json(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            self::fail('expected a JSON object body, got: ' . $body);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // --- container lifecycle -----------------------------------------------------

    private static function dockerAvailable(): bool
    {
        exec('docker info 2>/dev/null', $ignored, $exit);

        return $exit === 0;
    }

    private static function compose(string $args): void
    {
        $cmd = 'docker compose -f ' . escapeshellarg(self::$composeFile) . ' ' . $args . ' 2>&1';
        exec($cmd, $output, $exit);
        if ($exit !== 0 && !str_starts_with($args, 'down')) {
            self::fail("docker compose $args failed (exit $exit):\n" . implode("\n", $output));
        }
    }

    private static function waitForAllRuntimes(): void
    {
        foreach (array_keys(self::URLS) as $runtime) {
            $deadline = microtime(true) + 120;
            while (microtime(true) < $deadline) {
                if (self::probe($runtime) === 200) {
                    // Warm every worker in the pool before asserting anything. A
                    // request that lands on a still-initialising worker gets served
                    // correctly but can start a fresh session, which showed up as a
                    // flaky session-continuity failure right after `up --build`.
                    for ($i = 0; $i < 10; $i++) {
                        self::probe($runtime);
                    }
                    continue 2;
                }
                usleep(500_000);
            }
            self::fail(sprintf('the %s container did not start serving in time', $runtime));
        }
    }

    /**
     * Connection-refused tolerant, unlike request(): while a container is still
     * booting the port simply isn't there yet, which is not a test failure.
     */
    private static function probe(string $runtime): int
    {
        $ch = curl_init(self::URLS[$runtime] . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $status;
    }
}
