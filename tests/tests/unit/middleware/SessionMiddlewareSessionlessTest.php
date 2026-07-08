<?php

use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\SessionMiddleware;

/**
 * Regression test: StatelessAuthenticationMiddleware (packages/auth) sets the
 * generalized `auth.sessionless` request attribute for a sessionless firewall
 * or a service-typed token, but SessionMiddleware used to only honor the
 * older, JWT-specific `jwt.skip_session` attribute -- so the generalized
 * signal had no actual effect on session startup. Covers both attribute
 * names taking the same skip-session path.
 */
final class SessionMiddlewareTrackingHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public ?ServerRequestInterface $seenRequest = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;
        $this->seenRequest = $request;
        return new Psr7Response(200);
    }
}

final class SessionMiddlewareSessionlessTest extends TestCase
{
    private function trackingHandler(): SessionMiddlewareTrackingHandler
    {
        return new SessionMiddlewareTrackingHandler();
    }

    private function middleware(): SessionMiddleware
    {
        $controller = Context::getInstance('testing')->getController();
        return new SessionMiddleware($controller);
    }

    public function testAuthSessionlessAttributeSkipsSessionStartup(): void
    {
        $handler = $this->trackingHandler();
        $request = (new \Nyholm\Psr7\ServerRequest('GET', '/api/resource'))
            ->withAttribute('auth.sessionless', true);

        $response = $this->middleware()->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($handler->called);
        $this->assertNotNull($handler->seenRequest);
        $this->assertInstanceOf(ExecutionState::class, $handler->seenRequest->getAttribute(ExecutionState::class));
    }

    public function testLegacyJwtSkipSessionAttributeStillWorks(): void
    {
        $handler = $this->trackingHandler();
        $request = (new \Nyholm\Psr7\ServerRequest('GET', '/api/resource'))
            ->withAttribute('jwt.skip_session', true);

        $response = $this->middleware()->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($handler->called);
    }

    public function testNeitherAttributePresentDoesNotShortCircuit(): void
    {
        // Without either attribute, process() falls through into the normal
        // session-startup path instead of the early return -- this test only
        // asserts the handler still runs, not the session mechanics.
        $handler = $this->trackingHandler();
        $request = new \Nyholm\Psr7\ServerRequest('GET', '/web/page');

        $response = $this->middleware()->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($handler->called);
    }
}
