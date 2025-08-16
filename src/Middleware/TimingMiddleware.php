<?php
namespace Agavi\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Execution\ExecutionState;

/**
 * Records timing spans for downstream middleware execution.
 */
#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'bootstrap', priority: 100)]
class TimingMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $emitHeader = false) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $exec = $request->getAttribute(ExecutionState::class) ?? new ExecutionState();
        $exec->metrics = $exec->metrics ?? [];
        $request = $request->withAttribute(ExecutionState::class, $exec);
        $response = $handler->handle($request);
        $exec->metrics['total_ms'] = (microtime(true) - $start) * 1000;
        if($this->emitHeader && method_exists($response,'withHeader')) {
            $response = $response->withHeader('X-Agavi-Timing', json_encode(['total_ms'=>round($exec->metrics['total_ms'],2)], JSON_UNESCAPED_SLASHES));
        }
        return $response;
    }
}
