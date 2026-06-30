<?php

namespace Agavi\Middleware;

use Agavi\Controller\AgaviController;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Security\Csrf\CsrfManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies a CSRF token on every unsafe (state-changing) request before the
 * action is dispatched. Safe methods (GET/HEAD/OPTIONS/TRACE) pass through.
 *
 * The token is read from the configured form field (parsed body) or the
 * configured header (for XHR/fetch clients) and validated against the
 * session-stored token via {@see CsrfManager}. On failure the request is
 * short-circuited with HTTP 403 and the action never runs.
 *
 * Opt out per route by adding an `_csrf => false` default to the route, e.g.
 * for stateless API endpoints authenticated by token/signature.
 *
 * Runs after PayloadParsingMiddleware (so the body is parsed) and
 * RoutingMiddleware (so route opt-out is known), before DispatchMiddleware.
 *
 * @package    agavi
 * @subpackage middleware
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'pre', priority: 40, after: 'RoutingMiddleware', before: 'DispatchMiddleware')]
class CsrfValidationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AgaviController $controller)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $csrf = new CsrfManager($this->controller->getContext());

        if (!$csrf->isEnabled()) {
            return $handler->handle($request);
        }

        // Safe methods are never checked.
        if (in_array(strtoupper($request->getMethod()), $csrf->safeMethods(), true)) {
            return $handler->handle($request);
        }

        // Per-route opt-out: a route default of `_csrf => false`.
        $routeParams = $request->getAttribute('route_params');
        if (is_array($routeParams) && array_key_exists('_csrf', $routeParams) && $routeParams['_csrf'] === false) {
            return $handler->handle($request);
        }

        $submitted = $this->extractToken($request, $csrf);

        if ($submitted === null || !$csrf->isValid($submitted)) {
            if (\Agavi\Util\DebugFlags::$security) {
                AgaviDebugLogger::debug('[CsrfValidationMiddleware] rejected ' . $request->getMethod() . ' ' . $request->getUri()->getPath() . ' (token ' . ($submitted === null ? 'missing' : 'invalid') . ')', $this->controller->getContext());
            }
            $factory = new Psr17Factory();
            return $factory->createResponse(403)
                ->withHeader('X-Agavi-Csrf', 'failed')
                ->withBody($factory->createStream('CSRF token validation failed.'));
        }

        return $handler->handle($request);
    }

    /**
     * Extract the submitted token from the form field (parsed body) or header.
     */
    private function extractToken(ServerRequestInterface $request, CsrfManager $csrf): ?string
    {
        $field = $csrf->fieldName();
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && isset($parsed[$field]) && is_string($parsed[$field]) && $parsed[$field] !== '') {
            return $parsed[$field];
        }

        $header = $request->getHeaderLine($csrf->headerName());
        if ($header !== '') {
            return $header;
        }

        return null;
    }
}
