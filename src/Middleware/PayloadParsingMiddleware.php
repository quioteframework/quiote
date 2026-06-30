<?php

namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Middlewares\JsonPayload; // JSON parsing
use Middlewares\Utils\Dispatcher; // utility to run stack manually
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Logging\AgaviDebugLogger;

/**
 * Unified body parsing leveraging middlewares/payload.
 * Responsibilities:
 *  - Parse JSON (application/json, +json types) strict by default; 400 on invalid unless AGAVI_JSON_STRICT=0
 *  - Parse application/x-www-form-urlencoded (if not already parsed)
 *  - Preserve existing JsonBodyParsingMiddleware (temporary) by not re-parsing if parsedBody already set
 *
 * Order: should run before routing and after tracing.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'bootstrap', after: 'TraceMiddleware', before: 'RoutingMiddleware', priority: 70)]
class PayloadParsingMiddleware implements MiddlewareInterface
{
    private readonly bool $strict;
    private readonly JsonPayload $json;

    public function __construct(?bool $strict = null)
    {
        $this->strict = $strict ?? (getenv('AGAVI_JSON_STRICT') !== '0');
        $this->json = (new JsonPayload())
            ->options(JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            ->associative(true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Debug: log basic request info to help trace unexpected payload parse errors
        try {
            $ct = $request->getHeaderLine('Content-Type');
        } catch (\Throwable) {
            $ct = '';
        }
    AgaviDebugLogger::debug(sprintf('[PayloadParsingMiddleware] start method=%s ct=%s parsedBody=%s', $request->getMethod(), $ct, $request->getParsedBody() ? '1' : '0'));

        if ($request->getParsedBody()) { // already parsed by earlier adapter or test
            return $handler->handle($request);
        }
        try {
            // Delegate directly: Payload middleware mutates request passed to next handler.
            // If JSON invalid and JSON_THROW_ON_ERROR set, it will raise JsonException which we catch below.
            $ct = strtolower($request->getHeaderLine('Content-Type'));
            if ($ct && str_contains($ct, 'application/x-www-form-urlencoded')) {
                $raw = (string)$request->getBody();
                parse_str($raw, $data);
                if (is_array($data) && $data) {
                    AgaviDebugLogger::debug('[PayloadParsingMiddleware] parsed body: '. $raw);
                    $request = $request->withParsedBody($data);
                }
                return $handler->handle($request);
            }
            AgaviDebugLogger::debug("[PayloadParsingMiddleware] parsing body: " . $request->getBody());
            return $this->json->process($request, $handler);
        } catch (\JsonException | \Middlewares\Utils\HttpErrorException $je) {
            AgaviDebugLogger::debug('[PPM] PayloadParsingMiddleware invalid_json: ' . $je->getMessage());
            if ($this->strict) {
                $factory = new Psr17Factory();
                $resp = $factory->createResponse(400);
                $err = ['error' => 'invalid_json', 'message' => $je->getMessage()];
                return $resp->withHeader('Content-Type', 'application/json; charset=UTF-8')
                    ->withBody($factory->createStream(json_encode($err)));
            }
        } catch (\Throwable $e) {
            // Do not swallow arbitrary downstream exceptions; rethrow so ErrorHandlingMiddleware can format properly.
            throw $e;
        }
        return $handler->handle($request);
    }
}
