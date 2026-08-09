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

    /**
     * Appends this middleware's class name to the ExecutionState trace.
     *
     * Reuses the ExecutionState already on the request, or creates one and
     * attaches it, and records `static::class` so a subclass traces under its
     * own name. When the constructor enabled the header, the trace is re-read
     * from the shared ExecutionState after the downstream handler returns, so
     * entries appended by middleware further down the stack are included;
     * non-scalar entries are rendered as their debug type.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $execAttribute = $request->getAttribute(ExecutionState::class);
        $exec = $execAttribute instanceof ExecutionState ? $execAttribute : new ExecutionState();
        $exec->metrics ??= [];
        $existingTrace = $exec->metrics['trace'] ?? [];
        $trace = is_array($existingTrace) ? $existingTrace : [];
        $trace[] = static::class;
        $exec->metrics['trace'] = $trace;
        $request = $request->withAttribute(ExecutionState::class, $exec);
        $response = $handler->handle($request);
        if($this->emitHeader) {
            $response = $response->withHeader($this->headerName, implode(',', array_map(
                static fn(mixed $entry): string => is_scalar($entry) ? (string) $entry : get_debug_type($entry),
                self::currentTrace($exec),
            )));
        }
        return $response;
    }

    /**
     * Re-reads the trace from $exec at emit time (not the locally-scoped
     * array assembled earlier in process()) because $exec is a shared,
     * mutable object: nested TraceMiddleware instances further down the
     * pipeline append to the very same ExecutionState before the response
     * bubbles back out here.
     *
     * @return array<mixed>
     */
    private static function currentTrace(ExecutionState $exec): array
    {
        $trace = $exec->metrics['trace'] ?? [];
        return is_array($trace) ? $trace : [];
    }
}
