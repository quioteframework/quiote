<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Parses JSON request bodies and populates the PSR-7 parsed body array.
 * Strict mode: invalid JSON with an application/json (or +json) Content-Type returns 400.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', after: 'TraceMiddleware', before: 'RoutingMiddleware', priority: 80)]
class JsonBodyParsingMiddleware implements MiddlewareInterface
{
    private readonly bool $strict;

    public function __construct(?bool $strict = null)
    {
        // Allow opting out via env var; default strict true to surface client errors early.
        $this->strict = $strict ?? (getenv('QUIOTE_JSON_STRICT') !== '0');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if(in_array($method, ['GET','HEAD','OPTIONS'])) { return $handler->handle($request); }
        $ct = strtolower($request->getHeaderLine('Content-Type'));
        if($ct === '') { return $handler->handle($request); }
        if(!str_contains($ct, 'json')) { return $handler->handle($request); }
        // Only parse if body not already populated (avoid double work / overriding explicit tests)
        if($request->getParsedBody()) { return $handler->handle($request); }
        try {
            $raw = (string)$request->getBody();
            if($raw === '') { return $handler->handle($request); }
            // Tolerate UTF-8 BOM
            if(str_starts_with($raw, "\xEF\xBB\xBF")) { $raw = substr($raw,3); }
            $decoded = json_decode($raw, true);
            if(json_last_error() !== JSON_ERROR_NONE) {
                if($this->strict) {
                    $factory = new Psr17Factory();
                    $resp = $factory->createResponse(400);
                    $err = ['error' => 'invalid_json', 'message' => json_last_error_msg()];
                    return $resp->withHeader('Content-Type','application/json; charset=UTF-8')
                        ->withBody($factory->createStream(json_encode($err)));
                }
                return $handler->handle($request);
            }
            if(is_array($decoded)) {
                $request = $request->withParsedBody($decoded);
            }
        } catch(\Throwable) {
            if($this->strict) {
                $factory = new Psr17Factory();
                $resp = $factory->createResponse(400);
                $err = ['error' => 'json_parse_exception'];
                return $resp->withHeader('Content-Type','application/json; charset=UTF-8')
                    ->withBody($factory->createStream(json_encode($err)));
            }
        }
        return $handler->handle($request);
    }
}
