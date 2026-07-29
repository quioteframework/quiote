<?php

declare(strict_types=1);

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Middleware\Compiler\MiddlewareAttributeScanner;
use Quiote\Middleware\Compiler\MiddlewareDefinition;
use Quiote\Middleware\Compiler\MiddlewareOrderResolver;
use Quiote\Security\Headers\SecurityHeadersMiddleware;

final class SecurityHeadersTest extends TestCase
{
    private const CONFIG_KEYS = [
        'security_headers.enabled',
        'security_headers.csp',
        'security_headers.frame_options',
        'security_headers.content_type_options',
        'security_headers.referrer_policy',
        'security_headers.permissions_policy',
        'security_headers.hsts',
        'security_headers.hsts_max_age',
    ];

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CONFIG_KEYS as $key) {
            $this->originalConfig[$key] = Config::has($key) ? Config::get($key) : null;
        }
        Config::set('security_headers.enabled', true);
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

    private function plainHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
    }

    public function testDefaultHeadersAreSetOnHttpResponse(): void
    {
        $mw = new SecurityHeadersMiddleware();
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/x'), $this->plainHandler());

        $this->assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $resp->getHeaderLine('Referrer-Policy'));
        $this->assertSame("default-src 'self'", $resp->getHeaderLine('Content-Security-Policy'));
        $this->assertFalse($resp->hasHeader('Strict-Transport-Security'), 'HSTS must not be sent over plain http');
    }

    public function testCspIsConfigurable(): void
    {
        Config::set('security_headers.csp', "default-src 'self'; script-src 'self' https://cdn.example");
        $mw = new SecurityHeadersMiddleware();
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/x'), $this->plainHandler());
        $this->assertSame("default-src 'self'; script-src 'self' https://cdn.example", $resp->getHeaderLine('Content-Security-Policy'));
    }

    public function testHstsSentOnlyOverHttps(): void
    {
        Config::set('security_headers.hsts_max_age', 3600);
        $mw = new SecurityHeadersMiddleware();
        $resp = $mw->process(new ServerRequest('GET', 'https://localhost/x'), $this->plainHandler());
        $this->assertSame('max-age=3600; includeSubDomains', $resp->getHeaderLine('Strict-Transport-Security'));
    }

    public function testHstsCanBeDisabled(): void
    {
        Config::set('security_headers.hsts', false);
        $mw = new SecurityHeadersMiddleware();
        $resp = $mw->process(new ServerRequest('GET', 'https://localhost/x'), $this->plainHandler());
        $this->assertFalse($resp->hasHeader('Strict-Transport-Security'));
    }

    public function testPermissionsPolicyOmittedByDefault(): void
    {
        $mw = new SecurityHeadersMiddleware();
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/x'), $this->plainHandler());
        $this->assertFalse($resp->hasHeader('Permissions-Policy'));
    }

    public function testPermissionsPolicySentWhenConfigured(): void
    {
        Config::set('security_headers.permissions_policy', 'geolocation=()');
        $mw = new SecurityHeadersMiddleware();
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/x'), $this->plainHandler());
        $this->assertSame('geolocation=()', $resp->getHeaderLine('Permissions-Policy'));
    }

    public function testDisabledConfigBypassesMiddleware(): void
    {
        Config::set('security_headers.enabled', false);
        $mw = new SecurityHeadersMiddleware();
        $resp = $mw->process(new ServerRequest('GET', 'https://localhost/x'), $this->plainHandler());
        $this->assertFalse($resp->hasHeader('Content-Security-Policy'));
        $this->assertFalse($resp->hasHeader('X-Frame-Options'));
    }

    /**
     * The whole middleware is a no-op unless it sits *outside*
     * DispatchMiddleware: Dispatch is terminal — it never calls
     * `$handler->handle()` and builds its response from the rendered view — so
     * anything ordered after it decorates a response that is thrown away. Every
     * process()-level test above passes with the wrong placement, so assert the
     * resolved pipeline order directly against the real attributes.
     */
    public function testResolvesOutsideDispatchAndErrorHandling(): void
    {
        $scanner = new MiddlewareAttributeScanner();
        $definitions = $scanner->scan([
            SecurityHeadersMiddleware::class,
            \Quiote\Middleware\DispatchMiddleware::class,
            \Quiote\Middleware\ErrorHandlingMiddleware::class,
            \Quiote\Middleware\RoutingMiddleware::class,
        ]);
        $this->assertSame([], $scanner->getDiagnostics());

        $order = array_map(
            static fn(MiddlewareDefinition $d): string => $d->fqcn,
            (new MiddlewareOrderResolver())->resolve($definitions),
        );

        $securityHeaders = array_search(SecurityHeadersMiddleware::class, $order, true);
        $dispatch = array_search(\Quiote\Middleware\DispatchMiddleware::class, $order, true);
        $errorHandling = array_search(\Quiote\Middleware\ErrorHandlingMiddleware::class, $order, true);

        $this->assertIsInt($securityHeaders);
        $this->assertIsInt($dispatch);
        $this->assertIsInt($errorHandling);
        $this->assertLessThan($dispatch, $securityHeaders, 'Headers set deeper than DispatchMiddleware are discarded.');
        $this->assertLessThan($errorHandling, $securityHeaders, 'Error responses must carry the headers too.');
    }

    public function testHeadersAreAppliedToAnErrorResponseRenderedDownstream(): void
    {
        $mw = new SecurityHeadersMiddleware();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                // Stands in for ErrorHandlingMiddleware turning a throwable into
                // a response: the response this middleware decorates was never
                // the action's.
                return new Psr7Response(500);
            }
        };
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/boom'), $handler);
        $this->assertSame(500, $resp->getStatusCode());
        $this->assertSame("default-src 'self'", $resp->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('DENY', $resp->getHeaderLine('X-Frame-Options'));
    }

    public function testExistingResponseHeaderIsNotClobbered(): void
    {
        $mw = new SecurityHeadersMiddleware();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return (new Psr7Response(200))->withHeader('X-Frame-Options', 'SAMEORIGIN');
            }
        };
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/x'), $handler);
        $this->assertSame('SAMEORIGIN', $resp->getHeaderLine('X-Frame-Options'));
    }
}
