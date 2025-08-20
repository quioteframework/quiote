<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Middlewares\JsonPayload; // JSON parsing
use Middlewares\Utils\Dispatcher; // utility to run stack manually
use Nyholm\Psr7\Factory\Psr17Factory;

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
    private bool $strict;
    private JsonPayload $json;

    public function __construct(?bool $strict = null)
    {
        $this->strict = $strict ?? (getenv('AGAVI_JSON_STRICT') !== '0');
        $this->json = (new JsonPayload())
            ->options(JSON_THROW_ON_ERROR)
            ->associative(true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getParsedBody()) { // already parsed by earlier adapter or test
            return $handler->handle($request);
        }
        try {
            // Delegate directly: Payload middleware mutates request passed to next handler.
            // If JSON invalid and JSON_THROW_ON_ERROR set, it will raise JsonException which we catch below.
            $ct = strtolower($request->getHeaderLine('Content-Type'));
            if($ct && str_contains($ct,'application/x-www-form-urlencoded')) {
                $raw = (string)$request->getBody();
                parse_str($raw, $data);
                if(is_array($data) && $data) { $request = $request->withParsedBody($data); }
                return $handler->handle($request);
            }
            return $this->json->process($request, $handler);
        } catch (\JsonException|\Middlewares\Utils\HttpErrorException $je) {
            if ($this->strict) {
                $factory = new Psr17Factory();
                $resp = $factory->createResponse(400);
                $err = ['error' => 'invalid_json', 'message' => $je->getMessage()];
                return $resp->withHeader('Content-Type','application/json; charset=UTF-8')
                    ->withBody($factory->createStream(json_encode($err)));
            }
        } catch (\Throwable $e) {
            // Swallow other parsing errors (e.g., unexpected input types) unless strict requested
            if ($this->strict) {
                $factory = new Psr17Factory();
                $resp = $factory->createResponse(400);
                $err = ['error' => 'payload_parse_error'];
                return $resp->withHeader('Content-Type','application/json; charset=UTF-8')
                    ->withBody($factory->createStream(json_encode($err)));
            }
        }
        return $handler->handle($request);
    }
}
