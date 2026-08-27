<?php

namespace Quiote\ExceptionNotifier;

use Throwable;

/**
 * Mirrors the status-code mapping in
 * {@see \Quiote\Middleware\ErrorHandlingMiddleware::renderExceptionResponse()}
 * exactly, so `exception_notifier.min_status` filters against the same status
 * a client actually received. That mapping is private to the middleware and
 * not exposed as a reusable seam, so this must be kept in sync by hand if it
 * ever changes there.
 */
final class ExceptionStatusMapper
{
    private function __construct()
    {
    }

    public static function map(Throwable $exception): int
    {
        $map = [\InvalidArgumentException::class => 400, \DomainException::class => 422];
        foreach ($map as $class => $status) {
            if ($exception instanceof $class) {
                return $status;
            }
        }
        return 500;
    }
}
