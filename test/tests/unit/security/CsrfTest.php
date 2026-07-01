<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Security\Csrf\CsrfManager;
use Agavi\Middleware\CsrfValidationMiddleware;
use Agavi\Middleware\CsrfInjectionMiddleware;
use Agavi\Config\AgaviConfig;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Covers the CSRF token manager, validation middleware and injection middleware.
 */
class CsrfTest extends AgaviUnitTestCase
{
    /** @var object|null Original context storage, restored in tearDown(). */
    private $originalStorage;

    /** @var mixed Original core.csrf.enabled value, restored in tearDown(). */
    private $originalCsrfEnabled;

    protected function setUp(): void
    {
        parent::setUp();
        // CSRF is disabled in the test bootstrap; enable it for these tests and
        // restore the prior value in tearDown so it doesn't leak to other tests.
        $this->originalCsrfEnabled = AgaviConfig::get('core.csrf.enabled');
        AgaviConfig::set('core.csrf.enabled', true);

        // The testing.* environment uses the no-op AgaviNullStorage, so CSRF tokens
        // would never persist. Inject a simple in-memory storage so the manager can
        // store and retrieve tokens within the test process.
        $ctx = $this->getContext();
        $ro = new \ReflectionObject($ctx);
        $prop = $ro->getProperty('storage');
        $this->originalStorage = $prop->getValue($ctx);
        $prop->setValue($ctx, new class {
            private array $data = [];
            public function store(string $id, mixed $data): bool { $this->data[$id] = $data; return true; }
            public function retrieve($key) { return $this->data[$key] ?? null; }
            public function remove($key) { unset($this->data[$key]); }
        });
    }

    protected function tearDown(): void
    {
        AgaviConfig::set('core.csrf.enabled', $this->originalCsrfEnabled);
        try {
            $ctx = $this->getContext();
            $ro = new \ReflectionObject($ctx);
            $prop = $ro->getProperty('storage');
            $prop->setValue($ctx, $this->originalStorage);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function manager(): CsrfManager
    {
        return new CsrfManager($this->getContext());
    }

    private function controller()
    {
        return $this->getContext()->getController();
    }

    /** A handler that records it was reached and returns 200. */
    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public bool $called = false;
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $this->called = true;
                return new Psr7Response(200);
            }
        };
    }

    // --- CsrfManager ---

    public function testTokenRoundtrip(): void
    {
        $m = $this->manager();
        $token = $m->getTokenValue();
        $this->assertNotSame('', $token);
        $this->assertTrue($m->isValid($token), 'A freshly issued token must validate');
    }

    public function testInvalidTokenRejected(): void
    {
        $m = $this->manager();
        $m->getTokenValue(); // ensure a token exists
        $this->assertFalse($m->isValid('not-the-token'));
        $this->assertFalse($m->isValid(''));
    }

    // --- CsrfValidationMiddleware ---

    public function testSafeMethodBypassesValidation(): void
    {
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/x'), $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    /** A request bearing the configured session cookie, simulating a real browser session. */
    private function sessionCookieRequest(string $method, string $uri): ServerRequest
    {
        return (new ServerRequest($method, $uri))->withCookieParams([session_name() => 'fake-session-id']);
    }

    public function testUnsafeMethodWithoutTokenRejected(): void
    {
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $resp = $mw->process($this->sessionCookieRequest('POST', 'http://localhost/x'), $handler);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertSame('failed', $resp->getHeaderLine('X-Agavi-Csrf'));
        $this->assertFalse($handler->called, 'Action handler must not run on CSRF failure');
    }

    public function testUnsafeMethodWithValidTokenInBodyPasses(): void
    {
        $token = $this->manager()->getTokenValue();
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withParsedBody(['_csrf_token' => $token]);
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testUnsafeMethodWithValidTokenInHeaderPasses(): void
    {
        $token = $this->manager()->getTokenValue();
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withHeader('X-CSRF-Token', $token);
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testRouteOptOutBypassesValidation(): void
    {
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/webhook')
            ->withAttribute('route_params', ['_module' => 'X', '_action' => 'Y', '_csrf' => false]);
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    // --- Automatic exemptions: no ambient session credential to forge ---

    public function testRequestWithoutSessionCookieBypassesValidation(): void
    {
        // No cookies at all -> no ambient session-authenticated state for an attacker to ride.
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $resp = $mw->process(new ServerRequest('POST', 'http://localhost/x'), $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testRequestWithAuthorizationHeaderBypassesValidationEvenWithSessionCookie(): void
    {
        // A caller presenting its own credential (Bearer/Basic/JWT) cannot be forged by a
        // cross-site attacker the way an ambient session cookie can, regardless of whether a
        // session cookie also happens to be present.
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withHeader('Authorization', 'Bearer some.jwt.token');
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testForcedCsrfRouteStillValidatesDespiteAuthorizationHeader(): void
    {
        // `_csrf => true` overrides the automatic exemption for routes that need it anyway.
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withHeader('Authorization', 'Bearer some.jwt.token')
            ->withAttribute('route_params', ['_module' => 'X', '_action' => 'Y', '_csrf' => true]);
        $resp = $mw->process($req, $handler);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertFalse($handler->called);
    }

    public function testDisabledConfigBypassesValidation(): void
    {
        AgaviConfig::set('core.csrf.enabled', false);
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $resp = $mw->process(new ServerRequest('POST', 'http://localhost/x'), $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    // --- CsrfInjectionMiddleware ---

    private function htmlHandler(string $html): RequestHandlerInterface
    {
        return new class($html) implements RequestHandlerInterface {
            public function __construct(private string $html) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $factory = new Psr17Factory();
                return (new Psr7Response(200))
                    ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                    ->withBody($factory->createStream($this->html));
            }
        };
    }

    public function testInjectsHiddenFieldIntoPostForm(): void
    {
        $mw = new CsrfInjectionMiddleware($this->controller());
        $html = '<html><head></head><body><form method="post" action="/save"><input name="a"></form></body></html>';
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/'), $this->htmlHandler($html));
        $body = (string) $resp->getBody();
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertStringContainsString('type="hidden"', $body);
        // meta tag for JS clients
        $this->assertStringContainsString('name="csrf-token"', $body);
        // and the injected token must validate
        preg_match('/name="_csrf_token" value="([^"]+)"/', $body, $m);
        $this->assertNotEmpty($m[1] ?? '');
        $this->assertTrue($this->manager()->isValid(html_entity_decode($m[1])));
    }

    public function testDoesNotInjectIntoGetForm(): void
    {
        $mw = new CsrfInjectionMiddleware($this->controller());
        $html = '<html><body><form method="get" action="/search"><input name="q"></form></body></html>';
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/'), $this->htmlHandler($html));
        $this->assertStringNotContainsString('name="_csrf_token"', (string) $resp->getBody());
    }

    public function testRespectsDataCsrfOptOut(): void
    {
        $mw = new CsrfInjectionMiddleware($this->controller());
        $html = '<html><body><form method="post" data-csrf="off" action="/x"></form></body></html>';
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/'), $this->htmlHandler($html));
        $this->assertStringNotContainsString('name="_csrf_token"', (string) $resp->getBody());
    }

    public function testNonHtmlResponseUntouched(): void
    {
        $mw = new CsrfInjectionMiddleware($this->controller());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $factory = new Psr17Factory();
                return (new Psr7Response(200))
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream('{"form":"<form method=post>"}'));
            }
        };
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/'), $handler);
        $this->assertStringNotContainsString('_csrf_token', (string) $resp->getBody());
    }

    public function testInjectsIntoXhtmlAndStaysWellFormed(): void
    {
        $mw = new CsrfInjectionMiddleware($this->controller());
        $xhtml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>t</title></head>'
            . '<body><form method="post" action="/save"><input name="a" /></form></body></html>';
        $handler = new class($xhtml) implements RequestHandlerInterface {
            public function __construct(private string $xhtml) {}
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $factory = new Psr17Factory();
                return (new Psr7Response(200))
                    ->withHeader('Content-Type', 'application/xhtml+xml; charset=UTF-8')
                    ->withBody($factory->createStream($this->xhtml));
            }
        };
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/'), $handler);
        $body = (string) $resp->getBody();

        // The token was injected despite the non-text/html content type...
        $this->assertStringContainsString('name="_csrf_token"', $body);
        // ...as a self-closing tag...
        $this->assertMatchesRegularExpression('/name="_csrf_token"[^>]*\/>/', $body);
        // ...and the document is still well-formed XML.
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        $this->assertNotFalse($doc, 'injected XHTML must remain well-formed XML');
    }

    // --- XSRF-TOKEN cookie delivery (decoupled same-origin SPA path) ---

    private function jsonHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $factory = new Psr17Factory();
                return (new Psr7Response(200))
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream('{"ok":true}'));
            }
        };
    }

    /** Extract the (url-decoded) XSRF-TOKEN value from a response's Set-Cookie headers. */
    private function xsrfCookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            if (preg_match('/^XSRF-TOKEN=([^;]*)/', $line, $m)) {
                return rawurldecode($m[1]);
            }
        }
        return null;
    }

    public function testSetsReadableXsrfCookieForSessionRequest(): void
    {
        $mw = new CsrfInjectionMiddleware($this->controller());
        $resp = $mw->process($this->sessionCookieRequest('GET', 'http://localhost/api/data'), $this->jsonHandler());

        $setCookie = $resp->getHeader('Set-Cookie');
        $line = null;
        foreach ($setCookie as $c) {
            if (str_starts_with($c, 'XSRF-TOKEN=')) {
                $line = $c;
            }
        }
        $this->assertNotNull($line, 'a session-bearing request must receive an XSRF-TOKEN cookie');
        $this->assertStringContainsString('SameSite=Lax', $line);
        $this->assertStringContainsString('Path=/', $line);
        $this->assertStringNotContainsStringIgnoringCase('HttpOnly', $line, 'the SPA must be able to read this cookie from JS');

        // The delivered token must validate.
        $token = $this->xsrfCookie($resp);
        $this->assertNotNull($token);
        $this->assertTrue($this->manager()->isValid($token));
    }

    public function testDoesNotSetXsrfCookieWithoutSession(): void
    {
        // No session cookie => no ambient credential => CSRF doesn't apply, no token cookie.
        $mw = new CsrfInjectionMiddleware($this->controller());
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/api/data'), $this->jsonHandler());
        $this->assertNull($this->xsrfCookie($resp));
    }

    public function testSpaCookieHeaderRoundTrip(): void
    {
        // 1. A cookie-authenticated SPA does a GET; it receives the XSRF-TOKEN cookie
        //    even though the response is JSON (no server-rendered HTML/meta tag).
        $inject = new CsrfInjectionMiddleware($this->controller());
        $getResp = $inject->process($this->sessionCookieRequest('GET', 'http://localhost/api/data'), $this->jsonHandler());
        $token = $this->xsrfCookie($getResp);
        $this->assertNotNull($token, 'SPA must obtain a token from the cookie');

        // 2. It echoes the cookie value back in the header on a mutation; validation passes.
        $validate = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $postReq = $this->sessionCookieRequest('POST', 'http://localhost/api/data')
            ->withHeader('X-CSRF-Token', $token);
        $postResp = $validate->process($postReq, $handler);
        $this->assertSame(200, $postResp->getStatusCode());
        $this->assertTrue($handler->called);
    }
}
