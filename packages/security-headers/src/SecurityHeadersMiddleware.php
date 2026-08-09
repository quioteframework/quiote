<?php

namespace Quiote\Security\Headers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Http\RequestScheme;

/**
 * Adds standard hardening response headers (CSP, X-Content-Type-Options,
 * X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS). Only sets a
 * header when the response doesn't already carry one, so an action can still
 * override any of these on a per-route basis. HSTS is only added for https
 * requests — sending it over plain http is meaningless and, if the request
 * is deployed for local http development, actively unhelpful.
 *
 * Placement matters and is not negotiable: DispatchMiddleware is the terminal
 * middleware — it never calls `$handler->handle()` and builds its response
 * from the rendered view instead — so any middleware ordered after it decorates
 * a response nobody returns. This sits at the very outside of the pipeline, one
 * step further out than ErrorHandlingMiddleware, so the headers also land on
 * error and 404 responses that ErrorHandlingMiddleware renders in place of the
 * action's. */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 1100)]
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Adds the hardening headers to the response on the way back out.
     *
     * Runs the rest of the pipeline first, then sets each configured header
     * only if the response does not already carry it, so an action's own choice
     * always wins. Returns the response untouched when
     * `security_headers.enabled` is off. `Permissions-Policy` is only sent when
     * configured to a non-empty value, and HSTS only when enabled and the
     * request arrived over HTTPS.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!Config::getBool('security_headers.enabled', true)) {
            return $response;
        }

        $response = $this->withDefaultHeader($response, 'X-Content-Type-Options', Config::getString('security_headers.content_type_options', 'nosniff'));
        $response = $this->withDefaultHeader($response, 'X-Frame-Options', Config::getString('security_headers.frame_options', 'DENY'));
        $response = $this->withDefaultHeader($response, 'Referrer-Policy', Config::getString('security_headers.referrer_policy', 'strict-origin-when-cross-origin'));
        $response = $this->withDefaultHeader($response, 'Content-Security-Policy', Config::getString('security_headers.csp', "default-src 'self'"));

        $permissionsPolicy = Config::getString('security_headers.permissions_policy', '');
        if ($permissionsPolicy !== '') {
            $response = $this->withDefaultHeader($response, 'Permissions-Policy', $permissionsPolicy);
        }

        if (Config::getBool('security_headers.hsts', true) && RequestScheme::isHttps($request)) {
            $maxAge = Config::getInt('security_headers.hsts_max_age', 15_552_000);
            $response = $this->withDefaultHeader($response, 'Strict-Transport-Security', 'max-age=' . $maxAge . '; includeSubDomains');
        }

        return $response;
    }

    private function withDefaultHeader(ResponseInterface $response, string $name, string $value): ResponseInterface
    {
        if ($response->hasHeader($name)) {
            return $response;
        }
        return $response->withHeader($name, $value);
    }
}
