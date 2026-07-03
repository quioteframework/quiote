<?php

namespace Quiote\Event\Lifecycle;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Event\Event;

/**
 * Emitted by {@see \Quiote\Context::handle()} once the pipeline has produced a
 * response, just before it is returned to the runtime for emission. The last
 * hook that sees the full request + response together.
 */
final class ResponseSendingEvent extends Event
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly ResponseInterface $response,
    ) {}
}
