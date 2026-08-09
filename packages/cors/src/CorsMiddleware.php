<?php

namespace Quiote\Security\Cors;

use Quiote\Http\Psr17;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Exception\ConfigurationException;

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
    /**
     * Answers CORS preflights and decorates cross-origin responses.
     *
     * Passes the request straight through when `cors.enabled` is off or the
     * request carries no `Origin` header. Otherwise a preflight (an `OPTIONS`
     * carrying `Access-Control-Request-Method`) is answered here with a bare 204
     * plus the negotiated headers and never reaches the action; any other
     * cross-origin request is dispatched normally and its response decorated
     * afterwards. A response for an origin that is not allowed still gains a
     * `Vary: Origin`, so a shared cache cannot serve it to an allowed origin.
     *
     * @throws ConfigurationException if `cors.allowed_origins` contains `*`
     *                                while `cors.allow_credentials` is on.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Config::getBool('cors.enabled', false)) {
            return $handler->handle($request);
        }

        self::assertCredentialPolicyIsExpressible();

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
            // Vary even though nothing was added. Whether this response carries
            // CORS headers depends on the request's Origin, so a shared cache
            // that keys without Origin can serve this un-decorated body to an
            // allowed origin (breaking it) or, with the entries the other way
            // round, serve a decorated one to a rejected origin. The header
            // belongs on every response whose content depends on Origin, not
            // only the ones that ended up with an Access-Control-Allow-Origin.
            return $response->withAddedHeader('Vary', 'Origin');
        }

        return $this->decorate($response, $allowedOrigin, '', preflight: false);
    }

    /**
     * Refuse to serve a wildcard-plus-credentials configuration.
     *
     * The fetch specification forbids `Access-Control-Allow-Origin: *` together
     * with `Access-Control-Allow-Credentials: true`, so the pair cannot be sent.
     * The apparent workaround -- reflecting the caller's own origin, which
     * satisfies the spec and makes credentialed requests succeed -- must not be
     * taken: it grants every origin on the internet credentialed read access to
     * authenticated responses. CORS constrains browsers and nothing else, so a
     * browser refusing the pair is the protection, not an obstacle to route
     * around.
     *
     * That leaves refusing the configuration. A hard error rather than a
     * warning because there is no correct behaviour to fall back to, and
     * because `*` tends to be set before credentials are thought about -- the
     * two settings are often written at different times by different people,
     * which is exactly the case a log line gets missed for. The fix is to
     * enumerate the origins that genuinely need credentialed access, or to drop
     * credentials.
     *
     * @throws     ConfigurationException If `cors.allowed_origins` contains `*` while `cors.allow_credentials` is on.
     */
    private static function assertCredentialPolicyIsExpressible(): void
    {
        if (!Config::getBool('cors.allow_credentials', false)) {
            return;
        }
        if (!in_array('*', Config::getStringList('cors.allowed_origins', []), true)) {
            return;
        }

        throw new ConfigurationException(
            'cors.allowed_origins contains "*" while cors.allow_credentials is true. That pair cannot be '
            . 'sent: the fetch specification forbids Access-Control-Allow-Origin: * together with '
            . 'Access-Control-Allow-Credentials: true, and browsers reject it. Reflecting the caller\'s '
            . 'origin instead would make it work, which is worse -- it grants every origin on the internet '
            . 'credentialed read access to authenticated responses. Enumerate the origins that need '
            . 'credentialed access in cors.allowed_origins, or set cors.allow_credentials to false.'
        );
    }

    /**
     * @return string|null The value to echo back as Access-Control-Allow-Origin, or null if $origin isn't allowed.
     *
     * A configured `*` answers `*`, never the caller's own origin. The
     * credentialed variant of that configuration never reaches here at all --
     * see {@see assertCredentialPolicyIsExpressible()}.
     */
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
