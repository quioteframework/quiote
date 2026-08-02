<?php

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Security\Csrf\CsrfManager;
use Quiote\Security\Csrf\Middleware\CsrfValidationMiddleware;
use Quiote\Testing\UnitTestCase;

/** Records whether the action was reached. Named, not anonymous, so the assertions can be typed. */
final class CsrfAdversaryHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;

        return new Psr7Response(200);
    }
}

/**
 * The CSRF guarantee stated as an adversary table rather than as feature
 * assertions.
 *
 * Every other CSRF test asks "does this exemption exempt?" -- which is how the
 * exemptions came to be tested as features and never as holes. A test asserting
 * that a header-bearing request bypasses validation passes just as happily when
 * that bypass is a vulnerability, and one did, for as long as the bypass existed.
 *
 * This file inverts the question. It enumerates request shapes a cross-site
 * attacker can actually produce against a victim carrying an ambient session
 * cookie, and asserts that none of them reaches the handler. The table is the
 * artifact: when a new evasion shape turns up, it gets appended here, and the
 * assertion never changes.
 *
 * What an attacker controls, and therefore what belongs in the table: the method,
 * the path, the body (including any field name), any header a form or a
 * CORS-permitted fetch can set, and the token value. What they do NOT control,
 * and therefore what must never appear here as an exemption: the request
 * attributes the auth middleware sets after validating a credential, since those
 * are written server-side by code the attacker cannot reach.
 */
class CsrfExemptionAdversaryTest extends UnitTestCase
{
    /** @var mixed */
    private $originalCsrfEnabled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCsrfEnabled = Config::getBool('core.csrf.enabled');
        Config::set('core.csrf.enabled', true);
        $this->getContext()->setSessionBag(new InMemorySessionBag());
        // The production path: a real session manager, so the session cookie under
        // test is the QSID one a deployment actually issues.
        $this->installTestSessionManager();
        $this->assertSessionMechanismConfigured();
    }

    protected function tearDown(): void
    {
        Config::set('core.csrf.enabled', $this->originalCsrfEnabled);
        CsrfValidationMiddleware::resetWarnings();
        try {
            $this->getContext()->setSessionBag(null);
            $this->getContext()->setSessionManager(null);
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function sessionCookie(): string
    {
        return (new CsrfManager($this->getContext()))->sessionCookieName();
    }

    /**
     * A victim's browser request: carries the ambient session cookie, which is the
     * whole precondition for CSRF mattering at all.
     */
    private function victimRequest(string $method = 'POST'): ServerRequest
    {
        return (new ServerRequest($method, 'http://localhost/transfer-money'))
            ->withCookieParams([$this->sessionCookie() => 'victim-session-id']);
    }

    /**
     * A request carrying NO session cookie -- the shape a login POST has, since
     * a first-time visitor has nothing to send yet.
     *
     * This is the shape the sessionless exemption was written for, and the shape
     * login CSRF exploits: the attacker is not riding the victim's session, they
     * are creating one, by making the victim's browser submit the *attacker's*
     * credentials. Everything the victim then does happens in the attacker's
     * account.
     */
    private function sessionlessRequest(string $origin, string $method = 'POST'): ServerRequest
    {
        $request = new ServerRequest($method, 'http://localhost/login');

        return $origin === '' ? $request : $request->withHeader('Origin', $origin);
    }

    /**
     * @return array{0: CsrfValidationMiddleware, 1: CsrfAdversaryHandler}
     */
    private function middleware(): array
    {
        return [
            new CsrfValidationMiddleware($this->getContext()->getController()),
            new CsrfAdversaryHandler(),
        ];
    }

    /**
     * The invariant: no attacker-producible shape reaches a state-changing action.
     *
     * @param callable(self): ServerRequestInterface $build
     */
    #[DataProvider('attackerShapes')]
    public function testNoAttackerShapeReachesTheAction(string $_description, callable $build): void
    {
        [$middleware, $handler] = $this->middleware();

        $response = $middleware->process($build($this), $handler);

        $this->assertFalse($handler->called, 'the action must not run');
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('failed', $response->getHeaderLine('X-Quiote-Csrf'));
    }

    /** @return array<string, array{string, callable(self): ServerRequestInterface}> */
    public static function attackerShapes(): array
    {
        return [
            'no token at all' => [
                'a plain cross-site form POST',
                fn(self $t) => $t->victimRequest(),
            ],
            'empty token in body' => [
                'the field is present but blank',
                fn(self $t) => $t->victimRequest()->withParsedBody(['_csrf_token' => '']),
            ],
            'wrong token in body' => [
                'a guessed or stale value',
                fn(self $t) => $t->victimRequest()->withParsedBody(['_csrf_token' => 'not-the-token']),
            ],
            'wrong token in header' => [
                'the XHR delivery channel, with a guess',
                fn(self $t) => $t->victimRequest()->withHeader('X-CSRF-Token', 'not-the-token'),
            ],
            'token under a different field name' => [
                'submitting the right shape under the wrong key proves nothing',
                fn(self $t) => $t->victimRequest()->withParsedBody(['csrf' => 'anything']),
            ],
            // The header exemption used to fire on presence alone. A cross-site page
            // can attach this whenever CORS lets it, and the request still
            // authenticates via the ambient cookie.
            'bare Authorization header' => [
                'header presence is not proof of non-ambient authentication',
                fn(self $t) => $t->victimRequest()->withHeader('Authorization', 'Bearer not-a-real-token'),
            ],
            'lower-cased Authorization scheme' => [
                'the same, in the RFC-legal spelling that also skipped JWT auth',
                fn(self $t) => $t->victimRequest()->withHeader('Authorization', 'bearer not-a-real-token'),
            ],
            'Authorization plus a wrong token' => [
                'combining the two evasions',
                fn(self $t) => $t->victimRequest()
                    ->withHeader('Authorization', 'Bearer x')
                    ->withParsedBody(['_csrf_token' => 'wrong']),
            ],
            // An attacker cannot set a request attribute, but they can name a cookie.
            // Neither may be mistaken for the server-side auth signal.
            'a cookie named like the auth signal' => [
                'attacker-set cookies must not stand in for auth attributes',
                fn(self $t) => $t->victimRequest()->withCookieParams([
                    $t->sessionCookie() => 'victim-session-id',
                    'auth.stateless' => 'true',
                ]),
            ],
            'a body field named like the auth signal' => [
                'nor may body fields',
                fn(self $t) => $t->victimRequest()->withParsedBody([
                    'auth.stateless' => true,
                    'auth.sessionless' => true,
                ]),
            ],
            'a header named like the auth signal' => [
                'nor may headers',
                fn(self $t) => $t->victimRequest()
                    ->withHeader('Auth-Stateless', 'true')
                    ->withHeader('X-Auth-Sessionless', 'true'),
            ],
            // Unsafe methods reachable without a form, or via method override.
            'PUT' => ['unsafe methods other than POST', fn(self $t) => $t->victimRequest('PUT')],
            'PATCH' => ['unsafe methods other than POST', fn(self $t) => $t->victimRequest('PATCH')],
            'DELETE' => ['unsafe methods other than POST', fn(self $t) => $t->victimRequest('DELETE')],
            'lower-cased method' => [
                'the method comparison must be case-insensitive in the safe direction',
                fn(self $t) => $t->victimRequest('post'),
            ],
            'unknown method' => [
                'anything not on the safe list is unsafe',
                fn(self $t) => $t->victimRequest('PROPPATCH'),
            ],
            // Route params come from routing, not the request, but assert the opt-out
            // cannot be triggered by a value an attacker supplies.
            'a truthy-but-not-true route opt-out' => [
                'only a literal false opts out; "false"/0/null must not',
                fn(self $t) => $t->victimRequest()
                    ->withAttribute('route_params', ['_module' => 'X', '_action' => 'Y', '_csrf' => 'false']),
            ],
            'a null route opt-out' => [
                'an absent-ish value must not read as opted out',
                fn(self $t) => $t->victimRequest()
                    ->withAttribute('route_params', ['_module' => 'X', '_action' => 'Y', '_csrf' => null]),
            ],
            // Login CSRF. The sessionless exemption is correct about the request
            // it inspects -- there really is no ambient credential on it -- but a
            // login POST looks exactly the same, and letting it through let an
            // attacker authenticate the victim AS the attacker. The Origin is what
            // separates the two, and a browser always attaches it cross-site.
            'sessionless POST from a foreign origin' => [
                'login CSRF: no session to ride because the attacker is creating one',
                fn(self $t) => $t->sessionlessRequest('https://evil.example'),
            ],
            'sessionless POST from a look-alike origin' => [
                'a hostname that merely contains ours is not ours',
                fn(self $t) => $t->sessionlessRequest('https://localhost.evil.example'),
            ],
            'sessionless POST from an opaque origin' => [
                'a sandboxed iframe sends Origin: null, which is never same-origin',
                fn(self $t) => $t->sessionlessRequest('null'),
            ],
            'sessionless POST from an unparseable origin' => [
                'if same-origin cannot be established, it was not established',
                fn(self $t) => $t->sessionlessRequest('not a url'),
            ],
            'sessionless PUT from a foreign origin' => [
                'the same, on the other unsafe methods',
                fn(self $t) => $t->sessionlessRequest('https://evil.example', 'PUT'),
            ],
        ];
    }

    /**
     * The sessionless exemption must keep exempting the callers it exists for.
     *
     * Non-browser clients -- curl, an SDK, a server-to-server job -- send no
     * Origin, and cannot be made to carry a victim's ambient cookie in the first
     * place. If the origin check turned those away it would have traded a CSRF
     * hole for an outage on every API surface that relies on the exemption.
     */
    public function testSessionlessNonBrowserAndSameOriginRequestsStillPass(): void
    {
        foreach ([
            'no Origin at all (non-browser client)' => $this->sessionlessRequest(''),
            'our own origin'                        => $this->sessionlessRequest('http://localhost'),
            'our own origin, different scheme'      => $this->sessionlessRequest('https://localhost'),
            'our own origin, explicit port'         => $this->sessionlessRequest('https://localhost:8443'),
            'our own origin, different case'        => $this->sessionlessRequest('http://LOCALHOST'),
        ] as $label => $request) {
            [$middleware, $handler] = $this->middleware();
            $response = $middleware->process($request, $handler);

            $this->assertTrue($handler->called, $label . ' must reach the action');
            $this->assertSame(200, $response->getStatusCode(), $label);
        }
    }

    /**
     * The split-origin deployment: a browser hits a host this process never sees
     * under that name (a proxy rewrote Host, or the SPA is served elsewhere), so
     * the request's own host cannot vouch for the Origin and the operator has to.
     */
    public function testAConfiguredTrustedOriginIsAcceptedAsOurOwn(): void
    {
        $previous = Config::getArray('core.csrf.trusted_origins', []);
        Config::set('core.csrf.trusted_origins', ['https://app.example.com']);

        try {
            [$middleware, $handler] = $this->middleware();
            $response = $middleware->process($this->sessionlessRequest('https://app.example.com'), $handler);

            $this->assertTrue($handler->called, 'a configured trusted origin must reach the action');
            $this->assertSame(200, $response->getStatusCode());

            // ...and only that origin. The entry is matched whole, so it must not
            // widen into siblings of the same domain.
            [$middleware, $handler] = $this->middleware();
            $response = $middleware->process($this->sessionlessRequest('https://other.example.com'), $handler);

            $this->assertFalse($handler->called, 'an unlisted origin must still be rejected');
            $this->assertSame(403, $response->getStatusCode());
        } finally {
            Config::set('core.csrf.trusted_origins', $previous);
        }
    }

    /**
     * A foreign Origin is only decisive where the token check cannot run. Once a
     * session cookie is present the token is what decides, and a valid one from a
     * cross-origin caller (a CORS-permitted fetch from an allowed origin) must
     * still be honoured -- otherwise enabling CORS would silently break writes.
     */
    public function testAForeignOriginDoesNotOverrideAValidToken(): void
    {
        $token = (new CsrfManager($this->getContext()))->getTokenValue();

        [$middleware, $handler] = $this->middleware();
        $response = $middleware->process(
            $this->victimRequest()->withHeader('Origin', 'https://partner.example')->withHeader('X-CSRF-Token', $token),
            $handler,
        );

        $this->assertTrue($handler->called);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The other half of the guarantee: the legitimate paths still work. Without
     * this, the invariant above could be satisfied by rejecting everything.
     */
    public function testLegitimateRequestsStillPass(): void
    {
        $token = (new CsrfManager($this->getContext()))->getTokenValue();

        foreach ([
            'token in body'   => $this->victimRequest()->withParsedBody(['_csrf_token' => $token]),
            'token in header' => $this->victimRequest()->withHeader('X-CSRF-Token', $token),
            'safe method'     => $this->victimRequest('GET'),
            'no session at all' => (new ServerRequest('POST', 'http://localhost/api/thing')),
            'validated stateless credential' => $this->victimRequest()->withAttribute('auth.stateless', true),
        ] as $label => $request) {
            [$middleware, $handler] = $this->middleware();
            $response = $middleware->process($request, $handler);

            $this->assertTrue($handler->called, $label . ' must reach the action');
            $this->assertSame(200, $response->getStatusCode(), $label);
        }
    }
}
