<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\Controller;
use Quiote\Execution\ExecutionState;
use Quiote\Session\QuioteSessionBag;

/**
 * Bootstrap-phase session wiring for the framework pipeline.
 *
 * Loads or creates this request's session, installs it as the context's
 * {@see \Quiote\Session\SessionBagInterface} so every consumer -- the User
 * hierarchy, CSRF token storage, OIDC state, application code -- reaches the
 * same session, persists the user before the session is written, and bakes the
 * Set-Cookie onto the response.
 *
 * With no `session` factory slot configured there is no session to manage:
 * Context::getSessionBag() keeps answering a NullSessionBag, and this
 * middleware does nothing beyond ensuring an ExecutionState exists. That is the
 * shape a console command, a queue worker or a stateless API runs in.
 *
 * Distinct from {@see \Quiote\Session\SessionMiddleware}, which is the
 * standalone PSR-15 wiring for an application driving SessionManager outside
 * this pipeline. This one additionally owns the ExecutionState guarantee and
 * the request-state flush, both of which are pipeline concerns.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 900)]
class SessionMiddleware implements MiddlewareInterface
{
    private \Quiote\Logging\CategoryLogger $logger;

    public function __construct(private readonly Controller $controller)
    {
        $this->logger = \Quiote\Logging\Log::for($this);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->getAttribute(ExecutionState::class)) {
            $request = $request->withAttribute(ExecutionState::class, new ExecutionState());
        }

        $context = $this->controller->getContext();

        // A stateless machine/service-client request. `jwt.skip_session` is the
        // original attribute name; `auth.sessionless` is the generalized
        // replacement set by packages/auth's StatelessAuthenticationMiddleware,
        // which runs earlier (before: SessionMiddleware::class). Both are
        // honored so neither is silently ignored.
        $sessionless = (bool) ($request->getAttribute('jwt.skip_session') || $request->getAttribute('auth.sessionless'));
        $manager = $sessionless ? null : $context->getSessionManager();

        if ($manager === null) {
            try {
                return $handler->handle($request);
            } finally {
                // No session to persist into, so the user is not written -- a
                // token-derived identity must not be pushed into whatever
                // unrelated session the client may still carry. The flush is
                // still claimed, so the post-emit Context::reset() does not
                // attempt a late write of its own.
                try {
                    $context->flushRequestState(persistUser: false);
                } catch (\Throwable) {}
            }
        }

        $session = $manager->startFromRequest($request);
        $context->setSessionBag(new QuioteSessionBag($manager, $session, $request));

        try {
            $response = $handler->handle($request);
        } finally {
            try {
                // The user is the only writer of roles, credentials and
                // attributes, and it has to run before the session is
                // persisted below. Doing it after the response was emitted --
                // which is where it used to happen -- is what produced an
                // authenticated user with no credentials.
                $context->flushRequestState();
            } catch (\Throwable $t) {
                if ($this->logger->isEnabled(\Quiote\Logging\Level::Debug)) {
                    $this->logger->debug('[SessionMiddleware] flush error: ' . $t->getMessage());
                }
            }
        }

        return $manager->persistAndBakeCookies($session, $response);
    }
}
