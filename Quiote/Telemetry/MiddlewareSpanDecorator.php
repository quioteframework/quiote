<?php

namespace Quiote\Telemetry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps a single pipeline middleware in a child span named by its FQCN.
 * Reproduces, as real spans, what `TraceMiddleware` already records as a
 * flat name list.
 *
 * High cardinality/overhead — opt-in only via `telemetry.spans.middleware`
 * (default `false`). `MiddlewarePipeline::doBuild()` only constructs this
 * decorator at all when that setting is on, so a disabled pipeline pays zero
 * cost for this feature, not even an extra object per middleware.
 */
final class MiddlewareSpanDecorator implements MiddlewareInterface
{
    public function __construct(
        private readonly MiddlewareInterface $inner,
        private readonly string $label,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $span = Trace::span('Quiote.Middleware', $this->label);
        try {
            return $this->inner->process($request, $handler);
        } catch (\Throwable $e) {
            $span->recordException($e)->setStatusError($e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }
}
