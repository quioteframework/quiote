<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Security\Csrf\CsrfManager;
use Quiote\Security\Csrf\Middleware\CsrfValidationMiddleware;
use Quiote\Security\Csrf\Middleware\CsrfInjectionMiddleware;
use Quiote\Config\Config;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A handler that records it was reached and returns 200. */
final class CsrfRecordingHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;

        return new Psr7Response(200);
    }
}

/**
 * In-memory stand-in for the context storage: the testing.* environment wires
 * the no-op NullStorage, so CSRF tokens would never persist.
 */
final class CsrfArrayStorage
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function store(string $id, mixed $data): bool
    {
        $this->data[$id] = $data;

        return true;
    }

    public function retrieve(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}

/**
 * Covers the CSRF token manager, validation middleware and injection middleware.
 */
class CsrfTest extends UnitTestCase
{
    /** @var mixed Original core.csrf.enabled value, restored in tearDown(). */
    private $originalCsrfEnabled;

    protected function setUp(): void
    {
        parent::setUp();
        // CSRF is disabled in the test bootstrap; enable it for these tests and
        // restore the prior value in tearDown so it doesn't leak to other tests.
        $this->originalCsrfEnabled = Config::getBool('core.csrf.enabled');
        Config::set('core.csrf.enabled', true);

        // The testing.* environment uses the no-op NullStorage, so CSRF tokens
        // would never persist. Inject a simple in-memory storage so the manager can
        // store and retrieve tokens within the test process.
        $ctx = $this->getContext();
        $ctx->setSessionBag(new InMemorySessionBag());
    }

    protected function tearDown(): void
    {
        Config::set('core.csrf.enabled', $this->originalCsrfEnabled);
        CsrfValidationMiddleware::resetWarnings();
        try {
            $ctx = $this->getContext();
            $ctx->setSessionBag(null);
            // A manager installed by withSessionManager() would otherwise change
            // cookie-name resolution for every later test in the process.
            $ctx->setSessionManager(null);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function manager(): CsrfManager
    {
        return new CsrfManager($this->getContext());
    }

    private function controller(): \Quiote\Controller\Controller
    {
        return $this->getContext()->getController();
    }

    private function okHandler(): CsrfRecordingHandler
    {
        return new CsrfRecordingHandler();
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

    /**
     * A request bearing the configured session cookie, simulating a real browser
     * session. The name is resolved the same way production resolves it rather
     * than hardcoded: hardcoding session_name() here is what let the middleware
     * look for a cookie the framework never sets while these tests still passed.
     */
    private function sessionCookieRequest(string $method, string $uri): ServerRequest
    {
        return (new ServerRequest($method, $uri))
            ->withCookieParams([$this->manager()->sessionCookieName() => 'fake-session-id']);
    }

    public function testUnsafeMethodWithoutTokenRejected(): void
    {
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $resp = $mw->process($this->sessionCookieRequest('POST', 'http://localhost/x'), $handler);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertSame('failed', $resp->getHeaderLine('X-Quiote-Csrf'));
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

    public function testStatelesslyAuthenticatedRequestBypassesValidationEvenWithSessionCookie(): void
    {
        // A caller whose identity an authenticator already re-derived from its own
        // credential (JWT/API key) cannot be forged by a cross-site attacker the way
        // an ambient session cookie can. The signal is the request attribute the auth
        // package sets after validating, not the raw header.
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withHeader('Authorization', 'Bearer some.jwt.token')
            ->withAttribute('auth.stateless', true);
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testSessionlessAttributeAlsoBypassesValidation(): void
    {
        // The machine-client signal set by StatelessAuthenticationMiddleware for a
        // sessionless firewall / service token exempts too.
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withAttribute('auth.sessionless', true);
        $resp = $mw->process($req, $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testBareAuthorizationHeaderDoesNotBypassValidation(): void
    {
        // Regression: header presence alone used to exempt the request. It proves
        // nothing -- an attacker can attach `Authorization: Bearer <garbage>` while
        // the request still authenticates via the ambient session cookie, which made
        // the exemption a CSRF bypass. Without a validated-credential attribute the
        // token is still required.
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withHeader('Authorization', 'Bearer some.jwt.token');
        $resp = $mw->process($req, $handler);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertFalse($handler->called);
    }

    public function testForcedCsrfRouteStillValidatesDespiteStatelessAuth(): void
    {
        // `_csrf => true` overrides the automatic exemption for routes that need it anyway.
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = $this->sessionCookieRequest('POST', 'http://localhost/x')
            ->withHeader('Authorization', 'Bearer some.jwt.token')
            ->withAttribute('auth.stateless', true)
            ->withAttribute('route_params', ['_module' => 'X', '_action' => 'Y', '_csrf' => true]);
        $resp = $mw->process($req, $handler);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertFalse($handler->called);
    }

    // --- Session cookie name resolution (the exemption's actual predicate) ---

    /**
     * Install a real SessionManager so cookie-name resolution follows the
     * production path. Dropped again in tearDown().
     * @param array<string, mixed> $parameters
     */
    private function withSessionManager(array $parameters = []): \Quiote\Session\SessionManager
    {
        $persistence = new class implements \Quiote\Session\SessionPersistenceInterface {
            /** @var array<string, array<string, mixed>> */
            private array $rows = [];

            public function load(string $sid): ?array
            {
                return $this->rows[$sid] ?? null;
            }

            public function save(string $sid, array $data): void
            {
                $this->rows[$sid] = $data;
            }

            public function delete(string $sid): void
            {
                unset($this->rows[$sid]);
            }
        };

        $manager = new \Quiote\Session\SessionManager($persistence, $parameters);
        $this->getContext()->setSessionManager($manager);

        return $manager;
    }

    public function testSessionCookieNameComesFromTheSessionManagerNotExtSession(): void
    {
        // Regression: the exemption used to probe for a cookie named session_name()
        // ('PHPSESSID'), which SessionManager never sets -- so hasSessionCookie() was
        // always false, every request was exempt and CSRF validated nothing.
        $this->withSessionManager();
        $this->assertSame('QSID', $this->manager()->sessionCookieName());
        $this->assertNotSame(session_name(), $this->manager()->sessionCookieName());
    }

    public function testConfiguredCookieNameIsHonoured(): void
    {
        $this->withSessionManager(['cookie_name' => 'MYAPPSID']);
        $this->assertSame('MYAPPSID', $this->manager()->sessionCookieName());

        $req = (new ServerRequest('POST', 'http://localhost/x'))
            ->withCookieParams(['MYAPPSID' => 'abc']);
        $this->assertTrue($this->manager()->hasSessionCookie($req));
    }

    public function testRealSessionCookieIsValidatedNotExempted(): void
    {
        // The end-to-end shape of the bug: a browser carrying the framework's own
        // session cookie posting without a token must be rejected.
        $this->withSessionManager();
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $req = (new ServerRequest('POST', 'http://localhost/x'))
            ->withCookieParams(['QSID' => 'fake-session-id']);
        $resp = $mw->process($req, $handler);
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertFalse($handler->called);
    }

    public function testExtSessionNameCookieDoesNotCountAsASession(): void
    {
        // The mirror image: with a SessionManager configured, a stray PHPSESSID
        // cookie is not this application's session and must not make the request
        // look session-bearing.
        $this->withSessionManager();
        $req = (new ServerRequest('POST', 'http://localhost/x'))
            ->withCookieParams(['PHPSESSID' => 'not-ours']);
        $this->assertFalse($this->manager()->hasSessionCookie($req));
    }

    public function testFallsBackToExtSessionNameWithoutASessionManager(): void
    {
        // No session factory slot => the legacy storage/native-$_SESSION path, where
        // ext/session genuinely owns the cookie.
        $this->getContext()->setSessionManager(null);
        $this->assertFalse($this->manager()->hasSessionMechanism());
        $this->assertSame(session_name(), $this->manager()->sessionCookieName());
    }

    public function testDisabledConfigBypassesValidation(): void
    {
        Config::set('core.csrf.enabled', false);
        $mw = new CsrfValidationMiddleware($this->controller());
        $handler = $this->okHandler();
        $resp = $mw->process(new ServerRequest('POST', 'http://localhost/x'), $handler);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertTrue($handler->called);
    }

    // --- CsrfInjectionMiddleware ---

    private function htmlHandler(string $html): RequestHandlerInterface
    {
        return new readonly class($html) implements RequestHandlerInterface {
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
        if (preg_match('/name="_csrf_token" value="([^"]+)"/', $body, $m) !== 1) {
            $this->fail('the injected hidden field must carry a token value');
        }
        $this->assertTrue($this->manager()->isValid(html_entity_decode($m[1])));
    }

    /**
     * An HTML response with nothing to rewrite -- no form to protect and no
     * <head> to hold the meta tag -- must come back byte-for-byte, not
     * emptied or otherwise disturbed.
     */
    public function testHtmlWithNothingToInjectComesBackUnchanged(): void
    {
        $mw = new CsrfInjectionMiddleware($this->controller());
        $html = '<div><p>no forms here</p></div>';
        $resp = $mw->process(new ServerRequest('GET', 'http://localhost/'), $this->htmlHandler($html));

        $this->assertSame($html, (string) $resp->getBody());
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
        $handler = new readonly class($xhtml) implements RequestHandlerInterface {
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
