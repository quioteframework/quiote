<?php

declare(strict_types=1);

namespace Quiote\Error;

use Closure;
use Quiote\Config\Config;
use Throwable;

/**
 * Reports an exception the application caught and answered itself -- a 4xx with a message for the
 * user, a fallback value -- so developer tooling can still show it:
 *
 * ```php
 * public function __construct(private readonly ErrorReporting $errors) {}
 * // ...
 * } catch (ConnectException $e) {
 *     $this->errors->handled($e, 'AuthService unreachable; answered 400');
 *     return $this->badRequest(...);
 * }
 * ```
 *
 * A handled exception is not the request's outcome: it does not set the `Throwable` request
 * attribute `ErrorHandlingMiddleware` publishes for an exception that ended the request, so a
 * replay recording still classifies the request by the response it actually gave.
 *
 * Does nothing while `core.developer_exceptions` is off, so it is safe to leave in production code
 * paths; it does not log either, which stays the caller's job.
 *
 * Singleton-safe like {@see \Quiote\Request\RequestState}: it holds no request state and resolves
 * the current request's {@see HandledExceptions} on every call.
 */
final readonly class ErrorReporting
{
    /** @param Closure(): HandledExceptions $handledExceptions Answers the current request's list. */
    public function __construct(private Closure $handledExceptions)
    {
    }

    public function handled(Throwable $exception, ?string $note = null): void
    {
        if (!Config::getBool('core.developer_exceptions', false)) {
            return;
        }
        ($this->handledExceptions)()->add(new HandledException($exception, $note));
    }
}
