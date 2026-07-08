<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ExecutionState;

/**
 * Captures names of executed middleware for debugging.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 90)]
class TraceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $emitHeader = false, private readonly string $headerName = 'X-Quiote-Trace') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $exec = $request->getAttribute(ExecutionState::class) ?? new ExecutionState();
        $exec->metrics ??= [];
        $trace = $exec->metrics['trace'] ?? [];
        $trace[] = static::class;
        $exec->metrics['trace'] = $trace;
        $request = $request->withAttribute(ExecutionState::class, $exec);
        $response = $handler->handle($request);
        if($this->emitHeader) {
            $response = $response->withHeader($this->headerName, implode(',', $exec->metrics['trace'] ?? []));
        }
        return $response;
    }
}
