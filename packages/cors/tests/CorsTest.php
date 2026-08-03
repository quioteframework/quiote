<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Exception\ConfigurationException;
use Quiote\Security\Cors\CorsMiddleware;

final class CorsTest extends TestCase
{
    private const CONFIG_KEYS = ['cors.enabled', 'cors.allowed_origins', 'cors.allowed_methods', 'cors.allowed_headers', 'cors.exposed_headers', 'cors.allow_credentials', 'cors.max_age'];

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CONFIG_KEYS as $key) {
            $this->originalConfig[$key] = Config::has($key) ? Config::get($key) : null;
        }
        Config::set('cors.enabled', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalConfig as $key => $value) {
            if ($value === null) {
                Config::remove($key);
            } else {
                Config::set($key, $value);
            }
        }
        parent::tearDown();
    }

    private function okHandler(): CorsRecordingHandler
    {
        return new CorsRecordingHandler();
    }

    public function testDisabledConfigBypassesMiddleware(): void
    {
        Config::set('cors.enabled', false);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $resp = $mw->process((new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://a.example'), $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($handler->called);
    }

    public function testRequestWithoutOriginPassesThroughUntouched(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/x'), $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($handler->called);
    }

    public function testAllowedOriginGetsEchoedOnSimpleRequest(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://a.example');
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('https://a.example', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $resp->getHeaderLine('Vary'));
        $this->assertTrue($handler->called);
    }

    public function testDisallowedOriginGetsNoCorsHeaders(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://evil.example');
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($handler->called, 'action still runs; browser enforces same-origin policy client-side');
    }

    public function testWildcardOriginAllowsAnyOriginWithoutVaryHeader(): void
    {
        Config::set('cors.allowed_origins', ['*']);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://anything.example');
        $resp = $mw->process($req, $handler);
        $this->assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $resp->getHeaderLine('Vary'));
    }

    /**
     * The pair cannot be sent (the fetch spec forbids it) and must not be
     * worked around by reflecting the caller's origin, which would grant every
     * site credentialed read access to authenticated responses. Refusing is
     * what is left.
     */
    public function testWildcardWithCredentialsIsRefusedOutright(): void
    {
        Config::set('cors.allowed_origins', ['*']);
        Config::set('cors.allow_credentials', true);
        $mw = new CorsMiddleware();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://evil.example');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/cors\.allow_credentials/');
        $mw->process($req, $this->okHandler());
    }

    /**
     * Every request, not only cross-origin ones: the check sits ahead of the
     * Origin test so a misconfigured deployment fails on its first request
     * rather than on whichever later one happens to come from a browser.
     */
    public function testWildcardWithCredentialsIsRefusedEvenWithoutAnOriginHeader(): void
    {
        Config::set('cors.allowed_origins', ['*']);
        Config::set('cors.allow_credentials', true);
        $mw = new CorsMiddleware();

        $this->expectException(ConfigurationException::class);
        $mw->process(new ServerRequest('GET', 'http://localhost/x'), $this->okHandler());
    }

    public function testWildcardWithCredentialsIsNotCheckedWhileCorsIsDisabled(): void
    {
        // The setting pair is only contradictory once CORS is actually serving.
        // Refusing to boot an app that merely has it lying around, disabled,
        // would be a failure the operator cannot act on.
        Config::set('cors.enabled', false);
        Config::set('cors.allowed_origins', ['*']);
        Config::set('cors.allow_credentials', true);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();

        $resp = $mw->process((new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://a.example'), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame(200, $resp->getStatusCode());
    }

    /** Each half on its own stays legal; only the combination is refused. */
    public function testWildcardWithoutCredentialsAndCredentialsWithoutWildcardBothStillWork(): void
    {
        Config::set('cors.allowed_origins', ['*']);
        Config::set('cors.allow_credentials', false);
        $resp = (new CorsMiddleware())->process(
            (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://anything.example'),
            $this->okHandler(),
        );
        $this->assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $resp->getHeaderLine('Access-Control-Allow-Credentials'));

        Config::set('cors.allowed_origins', ['https://a.example']);
        Config::set('cors.allow_credentials', true);
        $resp = (new CorsMiddleware())->process(
            (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://a.example'),
            $this->okHandler(),
        );
        $this->assertSame('https://a.example', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testCredentialsHeaderOnlySetWhenConfigured(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        Config::set('cors.allow_credentials', true);
        $mw = new CorsMiddleware();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://a.example');
        $resp = $mw->process($req, $this->okHandler());
        $this->assertSame('true', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    /**
     * Whether a response carries CORS headers depends on the request's Origin,
     * so it varies on Origin whether or not the origin was allowed. Omitting
     * the header on the reject path lets a shared cache key the entry without
     * Origin and then serve it to the wrong caller.
     */
    public function testRejectedOriginStillGetsVaryOrigin(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        $mw = new CorsMiddleware();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://evil.example');

        $resp = $mw->process($req, $this->okHandler());

        $this->assertFalse($resp->hasHeader('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $resp->getHeaderLine('Vary'));
    }

    public function testExposedHeadersSentOnSimpleRequest(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        Config::set('cors.exposed_headers', ['X-Total-Count']);
        $mw = new CorsMiddleware();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://a.example');
        $resp = $mw->process($req, $this->okHandler());
        $this->assertSame('X-Total-Count', $resp->getHeaderLine('Access-Control-Expose-Headers'));
    }

    // --- Preflight ---

    public function testPreflightForAllowedOriginReturns204WithHeaders(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        Config::set('cors.allowed_methods', ['GET', 'POST']);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $req = (new ServerRequest('OPTIONS', 'http://localhost/x'))
            ->withHeader('Origin', 'https://a.example')
            ->withHeader('Access-Control-Request-Method', 'POST');
        $resp = $mw->process($req, $handler);
        $this->assertSame(204, $resp->getStatusCode());
        $this->assertSame('https://a.example', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST', $resp->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertFalse($handler->called, 'preflight must never reach the action');
    }

    public function testPreflightForDisallowedOriginReturns204WithoutCorsHeaders(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $req = (new ServerRequest('OPTIONS', 'http://localhost/x'))
            ->withHeader('Origin', 'https://evil.example')
            ->withHeader('Access-Control-Request-Method', 'POST');
        $resp = $mw->process($req, $handler);
        $this->assertSame(204, $resp->getStatusCode());
        $this->assertFalse($resp->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($handler->called);
    }

    public function testPreflightEchoesRequestedHeadersWhenNoneConfigured(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        $mw = new CorsMiddleware();
        $req = (new ServerRequest('OPTIONS', 'http://localhost/x'))
            ->withHeader('Origin', 'https://a.example')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'X-Custom-Header');
        $resp = $mw->process($req, $this->okHandler());
        $this->assertSame('X-Custom-Header', $resp->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testPreflightMaxAgeHeaderOnlyWhenConfigured(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        Config::set('cors.max_age', 3600);
        $mw = new CorsMiddleware();
        $req = (new ServerRequest('OPTIONS', 'http://localhost/x'))
            ->withHeader('Origin', 'https://a.example')
            ->withHeader('Access-Control-Request-Method', 'POST');
        $resp = $mw->process($req, $this->okHandler());
        $this->assertSame('3600', $resp->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testOptionsWithoutPreflightHeaderIsTreatedAsSimpleRequest(): void
    {
        // OPTIONS without Access-Control-Request-Method is not a CORS preflight.
        Config::set('cors.allowed_origins', ['https://a.example']);
        $mw = new CorsMiddleware();
        $handler = $this->okHandler();
        $req = (new ServerRequest('OPTIONS', 'http://localhost/x'))->withHeader('Origin', 'https://a.example');
        $resp = $mw->process($req, $handler);
        $this->assertTrue($handler->called);
        $this->assertSame(200, $resp->getStatusCode());
    }
}

final class CorsRecordingHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public function handle(ServerRequestInterface $r): ResponseInterface
    {
        $this->called = true;
        return new Psr7Response(200);
    }
}
