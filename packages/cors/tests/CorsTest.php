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

    public function testCredentialsHeaderOnlySetWhenConfigured(): void
    {
        Config::set('cors.allowed_origins', ['https://a.example']);
        Config::set('cors.allow_credentials', true);
        $mw = new CorsMiddleware();
        $req = (new ServerRequest('GET', 'http://localhost/x'))->withHeader('Origin', 'https://a.example');
        $resp = $mw->process($req, $this->okHandler());
        $this->assertSame('true', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
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
