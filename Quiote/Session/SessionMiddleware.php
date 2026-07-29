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
    /**
     * @param ?\Quiote\Context $context When given, this request's session is
     *        also installed as the context's {@see SessionBagInterface}, so the
     *        framework's own consumers -- the User hierarchy, CSRF token
     *        storage, OIDC state -- run against this session instead of the
     *        legacy `storage` slot. Without it the two remain independent, and
     *        an application gets two sessions and two cookies.
     */
    public function __construct(
        private readonly SessionManager $sessionManager,
        private readonly ?\Quiote\Context $context = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->sessionManager->startFromRequest($request);
        $request = $request->withAttribute(self::class, $session);

        $this->context?->setSessionBag(new QuioteSessionBag($this->sessionManager, $session, $request));

        try {
            $response = $handler->handle($request);
        } finally {
            // Persist the user before the session is written out, in the same
            // order and for the same reason as the legacy path: the user is the
            // only writer of roles and credentials, and a write after the
            // session has been serialized is a write nobody reads back.
            $this->context?->flushRequestState();
        }

        return $this->sessionManager->persistAndBakeCookies($session, $response);
    }
}
