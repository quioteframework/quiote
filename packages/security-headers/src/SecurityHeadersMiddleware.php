<?php

namespace Quiote\Security\Headers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;

/**
 * Adds standard hardening response headers (CSP, X-Content-Type-Options,
 * X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS). Runs after the
 * action so the response body/status are already final; only sets a header
 * when the response doesn't already carry one, so an action can still
 * override any of these on a per-route basis. HSTS is only added for https
 * requests — sending it over plain http is meaningless and, if the request
 * is deployed for local http development, actively unhelpful. */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'after_action', priority: 10, after: 'DispatchMiddleware')]
class SecurityHeadersMiddleware implements MiddlewareInterface
{
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

        if (Config::getBool('security_headers.hsts', true) && strtolower($request->getUri()->getScheme()) === 'https') {
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
