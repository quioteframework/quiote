<?php

namespace Quiote\Middleware;

/**
 * Session and authentication state for a middleware's debug lines.
 *
 * Both readings reach through the context into state that legitimately may not exist yet --
 * there may be no session backend, no user, or no context at all on a console or queue path --
 * so each answers a placeholder instead of failing. That tolerance is why they are confined
 * here: these values describe a request for a human reading a log, and no decision may ever be
 * taken from them. A middleware that needs the real session or user must ask the context
 * directly and handle its absence deliberately.
 *
 * @since      3.2.0
 */
trait RequestDiagnostics
{
    /**
     * The current session id, 'no-sid' when there is none to report.
     */
    private function diagnosticSessionId(): string
    {
        try {
            $sid = $this->controller->getContext()->getSessionBag()->getId();
            if ($sid !== '') {
                return $sid;
            }
        } catch (\Throwable) {
            // No session bag to ask; the native session below is the remaining source.
        }

        if (function_exists('session_id')) {
            $native = session_id();
            if (is_string($native) && $native !== '') {
                return $native;
            }
        }

        return 'no-sid';
    }

    /**
     * '1' when the current user is authenticated, '0' when it is not, 'na' when there is no
     * user to ask or it does not track authentication.
     */
    private function diagnosticAuthState(): string
    {
        try {
            $user = $this->controller->getContext()->getUser();
            if ($user instanceof \Quiote\User\ISecurityUser) {
                return $user->isAuthenticated() ? '1' : '0';
            }
        } catch (\Throwable) {
            // Reported as unavailable below.
        }

        return 'na';
    }
}
