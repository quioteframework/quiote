<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ExecutionState;

/**
 * Records timing spans for downstream middleware execution.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 100)]
class TimingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $emitHeader = false) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $exec = $request->getAttribute(ExecutionState::class) ?? new ExecutionState();
        $exec->metrics ??= [];
        $request = $request->withAttribute(ExecutionState::class, $exec);
        $response = $handler->handle($request);
        $exec->metrics['total_ms'] = (microtime(true) - $start) * 1000;
        if($this->emitHeader) {
            $encoded = json_encode(['total_ms'=>round($exec->metrics['total_ms'],2)], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ($encoded !== false) {
                $response = $response->withHeader('X-Quiote-Timing', $encoded);
            }
        }
        return $response;
    }
}
