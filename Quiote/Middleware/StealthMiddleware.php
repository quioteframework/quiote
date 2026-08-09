<?php

namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Strips framework-identifying response headers when `core.stealth_mode` is
 * enabled: any `X-Quiote-*` header, plus the names listed in
 * `core.stealth_additional_headers` (covers `X-Powered-By`, which doesn't
 * follow that prefix). Sits outside ErrorHandlingMiddleware so error/404
 * responses are stripped too, since DispatchMiddleware is terminal and never
 * calls `$handler->handle()` — only middleware ordered outside it ever sees
 * a response nobody else already returned.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 1200)]
class StealthMiddleware implements MiddlewareInterface
{
    /** @param array<int, string> $additionalHeaders */
    public function __construct(private readonly bool $enabled = false, private readonly array $additionalHeaders = ['X-Powered-By']) {}

    /**
     * Removes framework-identifying headers from the response on the way out.
     *
     * A no-op when stealth mode is disabled. Otherwise every response header
     * whose name starts with `X-Quiote-` (case-insensitively) is dropped,
     * along with each of the explicitly configured additional names that is
     * present. Only the response is touched; the request passes through
     * unchanged.
     *
     * Its high `bootstrap` priority puts it outside ErrorHandlingMiddleware, so
     * error and 404 responses are stripped as well.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!$this->enabled) {
            return $response;
        }

        $stripped = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            if (preg_match('/^X-Quiote-/i', $name) === 1) {
                $stripped[] = $name;
            }
        }
        foreach ($this->additionalHeaders as $name) {
            if ($response->hasHeader($name)) {
                $stripped[] = $name;
            }
        }

        foreach ($stripped as $name) {
            $response = $response->withoutHeader($name);
        }

        if ($stripped !== []) {
            \Quiote\Logging\Log::for($this)->debugWith(
                fn(): string => '[StealthMiddleware] stripped headers: ' . implode(', ', $stripped)
            );
        }

        return $response;
    }
}
