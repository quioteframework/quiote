<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Security\Csrf\CsrfManager;
use Quiote\Security\Csrf\CsrfPlugin;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Session\QuioteSessionBag;
use Quiote\Session\SessionBagInterface;
use Quiote\Session\SessionManager;
use Nyholm\Psr7\ServerRequest;

/**
 * End-to-end: CsrfPlugin's contributions actually enforce CSRF when reached
 * through the real, fully-wired pipeline (Quiote::bootstrap() -> CsrfPlugin
 * -> MiddlewareCatalog -> MiddlewarePipeline -> real routing -> real
 * dispatch), not just the middleware classes tested in isolation by
 * CsrfTest.php. tests/bootstrap.php disables CSRF globally for the rest of
 * the suite (core.csrf.enabled=false), so every test in the suite except
 * this one and CsrfTest.php never actually drives a request through an
 * enforcing CSRF middleware -- this test exists specifically to prove the
 * wiring, not just the standalone logic, actually works.
 *
 * Both cases target the same route, the sandbox app's `health` route
 * (/healthz -> Core/Health), so the token is the only difference between a
 * rejected request and one that dispatches. It has to be a route the
 * configured routing actually carries: SandboxRouting is built from the
 * generated route files, which hold the routing.xml routes and no
 * attribute-declared ones, and a path it cannot match would 404 after CSRF
 * passes -- which looks like success to any assertion that only rules out
 * 403.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class CsrfPipelineIntegrationTest extends TestCase
{
    private const ROUTE = 'http://localhost/healthz';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        Config::set('core.app_dir', $root . '/tests/sandbox/app', true, true);
        Config::set('core.module_dir', $root . '/tests/sandbox/app/Modules', true, true);
        require_once $root . '/vendor/autoload.php';
        require_once $root . '/Quiote/Quiote.php';
        \Quiote\Quiote::bootstrap('testing', 'web', ['prewarm' => false]);

        Config::set('core.csrf.enabled', true);
        MiddlewareCatalog::reset();
        (new CsrfPlugin())->register(new PluginRegistrar('quiote/csrf'));
    }

    private function context(): Context
    {
        return Context::getInstance('web');
    }

    private function pipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline($this->context());
    }

    /**
     * Resolved the way production resolves it rather than hardcoded to
     * session_name(): that hardcoding is what let the middleware probe for a
     * cookie the framework never sets while this test still passed.
     */
    private function sessionCookieRequest(string $method, string $uri): ServerRequest
    {
        $name = (new CsrfManager($this->context()))->sessionCookieName();

        return (new ServerRequest($method, $uri))->withCookieParams([$name => 'fake-session-id']);
    }

    /**
     * A request carrying a token the pipeline can actually resolve, built the
     * way a browser round-trip builds one: the token is written into a real
     * session, that session is persisted, and its real id travels back as the
     * session cookie.
     *
     * Nothing shorter works, because SessionMiddleware runs for real and
     * replaces whatever SessionBagInterface is bound with the session it
     * loads from the request's cookie. A cookie naming no persisted session
     * is indistinguishable from no cookie at all: the middleware starts a
     * fresh, empty one, and the token the test wrote is nowhere in it.
     */
    private function sessionCookieRequestWithToken(string $method, string $uri): ServerRequest
    {
        $ctx = $this->context();
        $manager = $ctx->getContainer()->get(SessionManager::class);
        $session = $manager->startFromRequest(new ServerRequest('GET', 'http://localhost/'));
        $ctx->getContainer()->set(
            SessionBagInterface::class,
            new QuioteSessionBag($manager, $session),
            Container::SCOPE_REQUEST,
        );

        $csrf = new CsrfManager($ctx);
        $token = $csrf->getTokenValue();
        $manager->persist($session);

        return (new ServerRequest($method, $uri))
            ->withCookieParams([$csrf->sessionCookieName() => $session->getId()])
            ->withParsedBody(['_csrf_token' => $token]);
    }

    public function testUnsafeRequestWithoutTokenIsRejectedByTheRealPipeline(): void
    {
        $response = $this->pipeline()->handle(
            $this->sessionCookieRequest('POST', self::ROUTE)
        );

        $this->assertSame(403, $response->getStatusCode(), 'a real dispatch, wired end-to-end through CsrfPlugin, must reject a tokenless unsafe request');
        $this->assertSame('failed', $response->getHeaderLine('X-Quiote-Csrf'));
    }

    public function testUnsafeRequestWithValidTokenReachesTheAction(): void
    {
        $response = $this->pipeline()->handle(
            $this->sessionCookieRequestWithToken('POST', self::ROUTE)
        );

        $this->assertSame(200, $response->getStatusCode(), 'a valid token must let the request reach the action, wired end-to-end through CsrfPlugin');
    }
}
