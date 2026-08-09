<?php
namespace Quiote\Exception;

/**
 * Raised by {@see \Quiote\Request\WebRequest::getParameter()} when a parameter that no
 * validator declared is read without a default.
 *
 * `ValidationMiddleware` prunes the request down to the parameters the action's validators
 * declared, and only those — plus anything application code set with `setParameter()` — are
 * whitelisted afterwards. An action with no validators at all therefore has an empty
 * whitelist: nothing was vetted, so nothing is readable. Reading raw input from a middleware
 * ordered before `ValidationMiddleware` sees values that disappear by the time the action
 * runs, which is the usual reason this surfaces.
 *
 * Passing a default (`getParameter('foo', null)`) returns that default instead of throwing;
 * it never returns the unvalidated value.
 */
class UnvalidatedParameterAccessException extends QuioteException {}
