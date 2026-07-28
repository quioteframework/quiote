<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Quiote\Runtime\Swoole\SwooleConverterOptions;
use Quiote\Runtime\Swoole\SwooleRequestConverter;
use Quiote\Runtime\Swoole\SwooleRequestSnapshot;

/**
 * Runs entirely off SwooleRequestSnapshot, so none of this needs ext-swoole --
 * which is the point of the snapshot existing at all.
 */
final class SwooleRequestConverterTest extends TestCase
{
    /**
     * @param array<string, mixed>|null  $server
     * @param array<string, string>|null $header
     * @param array<string, mixed>       $get
     * @param array<string, mixed>       $post
     * @param array<string, string>      $cookie
     * @param array<string, mixed>       $files
     */
    private static function snapshot(
        ?array $server = null,
        ?array $header = null,
        array $get = [],
        array $post = [],
        array $cookie = [],
        array $files = [],
        string $rawContent = '',
    ): SwooleRequestSnapshot {
        return new SwooleRequestSnapshot(
            server: $server ?? [
                'request_method' => 'GET',
                'request_uri' => '/thing',
                'server_protocol' => 'HTTP/1.1',
                'server_port' => 8080,
                'remote_addr' => '203.0.113.7',
            ],
            header: $header ?? ['host' => 'app.example'],
            get: $get,
            post: $post,
            cookie: $cookie,
            files: $files,
            rawContent: $rawContent,
        );
    }

    /** Swoole hands over a real temp file path, so the converter needs one too. */
    private static function tempUpload(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'swoole-upload');
        if ($path === false) {
            self::fail('could not create a temporary upload file');
        }
        file_put_contents($path, $contents);

        return $path;
    }

    public function testLowercaseSwooleServerKeysBecomeCgiNames(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot());

        // Swoole's own keys are request_method/request_uri/...; nothing in Quiote
        // reads those, so the translation is what makes the request usable.
        $params = $request->getServerParams();
        $this->assertSame('GET', $params['REQUEST_METHOD']);
        $this->assertSame('/thing', $params['REQUEST_URI']);
        $this->assertSame('HTTP/1.1', $params['SERVER_PROTOCOL']);
        $this->assertSame('8080', $params['SERVER_PORT']);
        $this->assertSame('203.0.113.7', $params['REMOTE_ADDR']);
        $this->assertSame('GET', $request->getMethod());
    }

    public function testScriptNameIsSynthesisedBecauseSwooleHasNoFrontController(): void
    {
        $request = (new SwooleRequestConverter(new SwooleConverterOptions(scriptName: '/app.php')))
            ->toPsr7(self::snapshot());

        // Routing reads $_SERVER['SCRIPT_NAME'] when generating URLs, and a
        // missing value corrupts links silently rather than erroring.
        $this->assertSame('/app.php', $request->getServerParams()['SCRIPT_NAME']);
        $this->assertSame('/app.php', $request->getServerParams()['PHP_SELF']);
    }

    public function testTheQueryStringIsReattachedWhenSwooleSplitsItOff(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'GET',
            'request_uri' => '/search',
            'query_string' => 'q=hello&page=2',
        ]));

        $this->assertSame('/search', $request->getUri()->getPath());
        $this->assertSame('q=hello&page=2', $request->getUri()->getQuery());
        $this->assertSame('/search?q=hello&page=2', $request->getServerParams()['REQUEST_URI']);
    }

    public function testAQueryStringAlreadyPresentInTheUriIsNotDuplicated(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'GET',
            'request_uri' => '/search?q=hello',
            'query_string' => 'q=hello',
        ]));

        $this->assertSame('q=hello', $request->getUri()->getQuery());
        $this->assertSame('/search?q=hello', $request->getServerParams()['REQUEST_URI']);
    }

    public function testPathInfoIsTheFallbackWhenThereIsNoRequestUri(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'GET',
            'path_info' => '/fallback',
        ]));

        $this->assertSame('/fallback', $request->getUri()->getPath());
    }

    public function testContentHeadersLoseTheHttpPrefixAsCgiRequires(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(header: [
            'host' => 'app.example',
            'content-type' => 'application/json',
            'content-length' => '9',
            'x-custom' => 'kept',
        ]));

        $params = $request->getServerParams();
        // WebRequest reads $_SERVER['CONTENT_TYPE'] directly, so an HTTP_-prefixed
        // key would be invisible to it.
        $this->assertSame('application/json', $params['CONTENT_TYPE']);
        $this->assertSame('9', $params['CONTENT_LENGTH']);
        $this->assertArrayNotHasKey('HTTP_CONTENT_TYPE', $params);
        $this->assertSame('kept', $params['HTTP_X_CUSTOM']);
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testHostBecomesBothTheUriAuthorityAndTheServerName(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(header: ['host' => 'app.example:8080']));

        $params = $request->getServerParams();
        $this->assertSame('app.example:8080', $params['HTTP_HOST']);
        // SERVER_NAME is the bare name, unlike HTTP_HOST.
        $this->assertSame('app.example', $params['SERVER_NAME']);
        $this->assertSame('app.example', $request->getUri()->getHost());
    }

    public function testAnIpv6HostKeepsItsAddressIntactInServerName(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(header: ['host' => '[2001:db8::1]:8080']));

        // Splitting on the last colon would otherwise mangle the address.
        $this->assertSame('[2001:db8::1]', $request->getServerParams()['SERVER_NAME']);
    }

    public function testTheProtocolVersionIsTakenFromSwoolesServerProtocol(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'GET',
            'request_uri' => '/',
            'server_protocol' => 'HTTP/2',
        ]));

        $this->assertSame('2', $request->getProtocolVersion());
    }

    public function testAMalformedProtocolFallsBackRatherThanProducingGarbage(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'GET',
            'request_uri' => '/',
            'server_protocol' => 'nonsense',
        ]));

        $this->assertSame('1.1', $request->getProtocolVersion());
    }

    public function testTlsTerminatedBySwooleItselfIsReflectedInTheScheme(): void
    {
        $request = (new SwooleRequestConverter(new SwooleConverterOptions(https: true)))
            ->toPsr7(self::snapshot());

        $this->assertSame('https', $request->getUri()->getScheme());
        $this->assertSame('on', $request->getServerParams()['HTTPS']);
        $this->assertSame('https', $request->getServerParams()['REQUEST_SCHEME']);
    }

    public function testPlainHttpDoesNotClaimTls(): void
    {
        $params = (new SwooleRequestConverter())->toPsr7(self::snapshot())->getServerParams();

        $this->assertArrayNotHasKey('HTTPS', $params);
        $this->assertSame('http', $params['REQUEST_SCHEME']);
    }

    public function testCookiesAndQueryParametersCarryOver(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(
            get: ['q' => 'hello'],
            cookie: ['QSESSID' => 'abc123'],
        ));

        $this->assertSame(['q' => 'hello'], $request->getQueryParams());
        $this->assertSame(['QSESSID' => 'abc123'], $request->getCookieParams());
    }

    public function testAFormBodyBecomesTheParsedBody(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(
            post: ['field' => 'value'],
            rawContent: 'field=value',
        ));

        $this->assertSame(['field' => 'value'], $request->getParsedBody());
        $this->assertSame('field=value', (string) $request->getBody());
    }

    public function testANonFormBodyLeavesParsedBodyNullForTheJsonMiddleware(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(
            header: ['host' => 'app.example', 'content-type' => 'application/json'],
            rawContent: '{"k":"v"}',
        ));

        // middlewares/payload parses JSON off the stream itself; pre-empting it
        // with an empty array would stop it doing so.
        $this->assertNull($request->getParsedBody());
        $this->assertSame('{"k":"v"}', (string) $request->getBody());
    }

    public function testAnEmptyBodyIsAnEmptyStreamRatherThanAFailure(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot());

        $this->assertSame('', (string) $request->getBody());
    }

    public function testSwoolesOwnRequestTimingsArePreferredForTelemetry(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'GET',
            'request_uri' => '/',
            'request_time' => 1700000000,
            'request_time_float' => 1700000000.25,
        ]));

        $params = $request->getServerParams();
        // TelemetryMiddleware measures wall time from REQUEST_TIME_FLOAT.
        $this->assertSame(1700000000, $params['REQUEST_TIME']);
        $this->assertSame(1700000000.25, $params['REQUEST_TIME_FLOAT']);
    }

    public function testTimingsFallBackToNowWhenSwooleOmitsThem(): void
    {
        $params = (new SwooleRequestConverter())->toPsr7(self::snapshot())->getServerParams();

        $this->assertIsInt($params['REQUEST_TIME']);
        $this->assertIsFloat($params['REQUEST_TIME_FLOAT']);
        $this->assertGreaterThan(0, $params['REQUEST_TIME']);
    }

    public function testASingleUploadBecomesAPsr7UploadedFile(): void
    {
        $tmp = self::tempUpload('contents');

        try {
            $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(files: [
                'doc' => [
                    'name' => 'report.pdf',
                    'type' => 'application/pdf',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 8,
                ],
            ]));

            $file = $request->getUploadedFiles()['doc'];
            $this->assertInstanceOf(UploadedFileInterface::class, $file);
            $this->assertSame('report.pdf', $file->getClientFilename());
            $this->assertSame('application/pdf', $file->getClientMediaType());
            $this->assertSame(UPLOAD_ERR_OK, $file->getError());
            $this->assertSame('contents', (string) $file->getStream());
        } finally {
            @unlink($tmp);
        }
    }

    public function testAMultiFileFieldIsNormalisedFromParallelArrays(): void
    {
        $first = self::tempUpload('one');
        $second = self::tempUpload('two');

        try {
            $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(files: [
                'docs' => [
                    'name' => ['a.txt', 'b.txt'],
                    'type' => ['text/plain', 'text/plain'],
                    'tmp_name' => [$first, $second],
                    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                    'size' => [3, 3],
                ],
            ]));

            $group = $request->getUploadedFiles()['docs'];
            $this->assertIsArray($group);
            $this->assertCount(2, $group);
            $firstUpload = $group[0];
            $secondUpload = $group[1];
            $this->assertInstanceOf(UploadedFileInterface::class, $firstUpload);
            $this->assertInstanceOf(UploadedFileInterface::class, $secondUpload);
            $this->assertSame('a.txt', $firstUpload->getClientFilename());
            $this->assertSame('two', (string) $secondUpload->getStream());
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function testAFailedUploadIsDescribedRatherThanOpened(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(files: [
            'doc' => [
                'name' => 'too-big.pdf',
                'type' => 'application/pdf',
                // Swoole still reports a path, but there is nothing readable there.
                'tmp_name' => '/nonexistent/swoole-upload',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ],
        ]));

        $file = $request->getUploadedFiles()['doc'];
        $this->assertInstanceOf(UploadedFileInterface::class, $file);
        $this->assertSame(UPLOAD_ERR_INI_SIZE, $file->getError());
        $this->assertSame('too-big.pdf', $file->getClientFilename());
    }

    public function testAnUploadWithNoTempPathIsSkippedRatherThanFatal(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(files: [
            'doc' => ['name' => 'x.txt', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE],
        ]));

        $this->assertSame([], $request->getUploadedFiles());
    }

    public function testNoFilesAtAllIsAnEmptyUploadSet(): void
    {
        $this->assertSame([], (new SwooleRequestConverter())->toPsr7(self::snapshot())->getUploadedFiles());
    }

    public function testTheMethodIsUppercasedSoDispatchMatches(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'post',
            'request_uri' => '/',
        ]));

        $this->assertSame('POST', $request->getMethod());
    }

    public function testAnEmptyRequestUriStillProducesARootPath(): void
    {
        $request = (new SwooleRequestConverter())->toPsr7(self::snapshot(server: [
            'request_method' => 'GET',
            'request_uri' => '',
        ]));

        $this->assertSame('/', $request->getUri()->getPath());
    }
}
