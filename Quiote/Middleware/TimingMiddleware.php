<?php
namespace Quiote\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ExecutionState;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;

/**
 * Records timing spans for downstream middleware execution.
 */
#[\Quiote\Middleware\Attribute\Middleware(phase: 'bootstrap', priority: 100)]
class TimingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $emitHeader = false,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    /**
     * Measures total pipeline time into the request's ExecutionState metrics.
     *
     * Runs at the head of the `bootstrap` phase so the measurement spans
     * essentially the whole stack. Reuses the ExecutionState already on the
     * request, or creates one and attaches it, then writes `total_ms` into its
     * metrics after the downstream handler returns. The `X-Quiote-Timing`
     * header is added only when the constructor enabled it, and is skipped
     * without complaint if the metrics cannot be JSON-encoded.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = $this->clock->monotonic();
        $execAttribute = $request->getAttribute(ExecutionState::class);
        $exec = $execAttribute instanceof ExecutionState ? $execAttribute : new ExecutionState();
        $exec->metrics ??= [];
        $request = $request->withAttribute(ExecutionState::class, $exec);
        $response = $handler->handle($request);
        $exec->metrics['total_ms'] = ($this->clock->monotonic() - $start) * 1000;
        if($this->emitHeader) {
            $encoded = json_encode(['total_ms'=>round($exec->metrics['total_ms'],2)], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ($encoded !== false) {
                $response = $response->withHeader('X-Quiote-Timing', $encoded);
            }
        }
        return $response;
    }
}
