<?php

use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Middleware\SessionMiddleware;

/**
 * Covers item 4b of PERF_PLAN.md: SessionMiddleware used to unconditionally
 * call storage->startup() (session_name()/session_start()) for every
 * non-sessionless request, even a cookieless crawler/first-time-visitor hit
 * with nothing to load and no session use in the action. That means a
 * session file/DB row plus Set-Cookie for every such request, and the
 * session lock held for the full request duration. Fix: only eagerly start
 * when the request actually carries the session cookie; a cookieless
 * request that goes on to actually use the session still gets one via
 * SessionStorage::store()'s own lazy @session_start() fallback.
 *
 * SessionMiddleware calls storage->shutdown() (session_write_close()) after
 * the handler runs, unconditionally, whether or not a session was started --
 * so session_status() always reads PHP_SESSION_NONE by the time process()
 * returns, regardless of what happened in between. These tests capture the
 * status from inside the handler (before shutdown() runs) instead.
 */
final class SessionMiddlewareLazyStartTest extends TestCase
{
    private function statusCapturingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?int $statusDuringHandle = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->statusDuringHandle = session_status();
                return new Psr7Response(200);
            }
        };
    }

    private function middleware(string $context): SessionMiddleware
    {
        $controller = Context::getInstance($context)->getController();
        return new SessionMiddleware($controller);
    }

    #[RunInSeparateProcess]
    public function testCookielessRequestDoesNotEagerlyStartASession(): void
    {
        $request = new \Nyholm\Psr7\ServerRequest('GET', '/web/page');
        $handler = $this->statusCapturingHandler();

        $response = $this->middleware('session-middleware-lazy-start-test::tests-cookieless')
            ->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame(PHP_SESSION_ACTIVE, $handler->statusDuringHandle);
    }

    #[RunInSeparateProcess]
    public function testRequestCarryingSessionCookieStillStartsEagerly(): void
    {
        $_COOKIE['Quiote'] = 'some-existing-session-id';
        $request = (new \Nyholm\Psr7\ServerRequest('GET', '/web/page'))
            ->withCookieParams(['Quiote' => 'some-existing-session-id']);
        $handler = $this->statusCapturingHandler();

        $response = $this->middleware('session-middleware-lazy-start-test::tests-with-cookie')
            ->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PHP_SESSION_ACTIVE, $handler->statusDuringHandle);
    }
}
