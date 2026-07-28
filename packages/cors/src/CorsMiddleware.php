<?php

namespace Quiote\Security\Cors;

use Quiote\Http\Psr17;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;

/**
 * Cross-Origin Resource Sharing (CORS) handling. Preflight (`OPTIONS` with an
 * `Access-Control-Request-Method` header) requests are answered directly with
 * a 204 and the negotiated CORS headers, without dispatching to the action.
 * Actual cross-origin requests are dispatched as normal, then get their
 * response decorated with the negotiated headers. Requests without an
 * `Origin` header are not cross-origin and pass through untouched.
 * Runs after RoutingMiddleware (so route-level overrides could be added
 * later) and before DispatchMiddleware (so preflight never reaches an
 * action). */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'before_action', priority: 50, after: 'RoutingMiddleware', before: 'DispatchMiddleware')]
class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Config::getBool('cors.enabled', false)) {
            return $handler->handle($request);
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return $handler->handle($request);
        }

        $allowedOrigin = $this->negotiateOrigin($origin);

        $isPreflight = strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method');

        if ($isPreflight) {
            $factory = Psr17::factory();
            $response = $factory->createResponse(204);
            return $this->decorate($response, $allowedOrigin, $request->getHeaderLine('Access-Control-Request-Headers'), preflight: true);
        }

        $response = $handler->handle($request);

        if ($allowedOrigin === null) {
            return $response;
        }

        return $this->decorate($response, $allowedOrigin, '', preflight: false);
    }

    /** @return string|null The value to echo back as Access-Control-Allow-Origin, or null if $origin isn't allowed. */
    private function negotiateOrigin(string $origin): ?string
    {
        $allowed = Config::getStringList('cors.allowed_origins', []);

        if (in_array('*', $allowed, true)) {
            return '*';
        }

        return in_array($origin, $allowed, true) ? $origin : null;
    }

    private function decorate(ResponseInterface $response, ?string $allowedOrigin, string $requestedHeaders, bool $preflight): ResponseInterface
    {
        if ($allowedOrigin === null) {
            return $response;
        }

        $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);

        if ($allowedOrigin !== '*') {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        if (Config::getBool('cors.allow_credentials', false)) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($preflight) {
            $methods = Config::getStringList('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
            $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $methods));

            $headers = Config::getStringList('cors.allowed_headers', []);
            $allowHeaders = $headers !== [] ? implode(', ', $headers) : $requestedHeaders;
            if ($allowHeaders !== '') {
                $response = $response->withHeader('Access-Control-Allow-Headers', $allowHeaders);
            }

            $maxAge = Config::getInt('cors.max_age', 0);
            if ($maxAge > 0) {
                $response = $response->withHeader('Access-Control-Max-Age', (string) $maxAge);
            }
        } else {
            $exposed = Config::getStringList('cors.exposed_headers', []);
            if ($exposed !== []) {
                $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposed));
            }
        }

        return $response;
    }
}
