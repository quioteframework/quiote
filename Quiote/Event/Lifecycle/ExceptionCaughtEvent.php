<?php

namespace Quiote\Event\Lifecycle;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Event\Event;
use Throwable;

/**
 * Emitted by {@see \Quiote\Middleware\ErrorHandlingMiddleware} whenever it
 * catches an unhandled throwable, before rendering the error response.
 * Lets plugins hook error reporting (e.g. Sentry/Bugsnag) uniformly instead
 * of each wiring its own constructor-injected callback.
 */
final class ExceptionCaughtEvent extends Event
{
    public function __construct(
        public readonly Throwable $exception,
        public readonly ServerRequestInterface $request,
    ) {}
}
