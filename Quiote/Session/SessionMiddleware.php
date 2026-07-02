<?php

declare(strict_types=1);

namespace Quiote\Session;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Opt-in PSR-15 middleware wiring SessionManager into the request lifecycle:
 * loads/creates the session before the handler runs and attaches it to the
 * request as an attribute keyed by self::class, then persists + bakes the
 * Set-Cookie header onto the response afterwards.
 *
 * This is a self-contained alternative to hand-rolling session handling: register
 * it via MiddlewareCatalog::register(SessionMiddleware::class, fn() => new
 * SessionMiddleware($sessionManager)) instead of reimplementing cookie/regenerate
 * logic per-app.
 *
 * Downstream code reads/mutates the session via:
 *   $session = $request->getAttribute(SessionMiddleware::class);
 *   $session->set('user_id', $id);
 *
 * Session is a mutable object (not a plain array) specifically so this works: PSR-7
 * requests fork on every withAttribute() call further down the pipeline, but the
 * Session instance itself is shared, so mutations made deep in a handler are still
 * visible here once control returns.
 */
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionManager $sessionManager) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->sessionManager->startFromRequest($request);
        $request = $request->withAttribute(self::class, $session);

        $response = $handler->handle($request);

        return $this->sessionManager->persistAndBakeCookies($session, $response);
    }
}
