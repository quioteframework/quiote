<?php

namespace Quiote\Event\Lifecycle;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Event\Event;

/**
 * Emitted by {@see \Quiote\Middleware\RoutingMiddleware} immediately after a
 * request is matched to a module/action, before the matched request is handed
 * to the rest of the pipeline.
 */
final class RequestMatchedEvent extends Event
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly string $module,
        public readonly string $action,
        public readonly ?string $routeName,
        public readonly string $outputType,
    ) {}
}
