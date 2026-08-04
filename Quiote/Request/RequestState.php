<?php

namespace Quiote\Request;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Context;

/**
 * The seam onto the request that is current *now*.
 *
 * {@see WebRequest} is immutable, so every mutation produces a new instance and the request is
 * replaced many times within a single request -- validation alone replaces it. Anything that holds
 * a `WebRequest` therefore holds a snapshot, and a snapshot taken at construction is the
 * pre-validation request: reading a parameter from it bypasses the strict-validation whitelist.
 * That is why the container refuses to inject a request into a singleton at all.
 *
 * This is what to inject instead. It resolves through to the context on every call and holds
 * nothing, so there is no instance for a stale request to hide in:
 *
 * ```php
 * public function __construct(private readonly RequestState $requestState) {}
 * // ...
 * $rd = FormPopulationConfig::merge($this->requestState->current(), [...]);
 * $this->requestState->publish($rd);
 * ```
 *
 * Inside an action or a view, prefer the `WebRequest` parameter already handed to
 * `executeRead()`/`execute*()` -- it is the current request by construction. This class is for
 * publishing a replacement, and for collaborators that outlive a single request.
 *
 * @since      4.0.0
 */
final class RequestState
{
    public function __construct(private readonly Context $context) {}

    /**
     * The request as of this call, built from the factory metadata if the worker request boundary
     * has cleared it.
     *
     * @since      4.0.0
     */
    public function current(): WebRequest
    {
        return $this->context->getRequest();
    }

    /**
     * Install a replacement as the current request.
     *
     * Every `WebRequest` mutator returns a new instance rather than mutating in place, so the
     * result has to be published or the change is simply discarded -- silently, because dropping a
     * return value is not an error. Anything that mutates the request must end here.
     *
     * A foreign PSR-7 request is normalized into a {@see WebRequest} on the way in, so
     * {@see current()} always answers one.
     *
     * @since      4.0.0
     */
    public function publish(WebRequest|ServerRequestInterface $request): void
    {
        $this->context->setRequest($request);
    }
}
