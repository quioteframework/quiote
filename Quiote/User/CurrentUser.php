<?php

namespace Quiote\User;

use Quiote\Context;

/**
 * The seam onto the user of the request being served *now*.
 *
 * The user is stable within a request -- it is replaced only at the worker request boundary and by
 * the pre-request deferral, never mid-request -- so an object that lives for one execution can
 * simply inject {@see User} or {@see ISecurityUser} and hold it. Actions and views are built per
 * execution, so that is the right thing for them:
 *
 * ```php
 * public function __construct(private readonly SecurityUser $user) {}
 * ```
 *
 * A singleton cannot do that. It is constructed once and keeps whatever it was handed, so the
 * user it captured on request 1 would be served to every later request in a persistent worker --
 * a cross-user identity leak, which is why the container refuses that wiring outright.
 *
 * This is what a singleton injects instead. It resolves through to the context on every call and
 * deliberately memoizes nothing: memoizing here would reintroduce exactly the leak the container's
 * captive-dependency guard cannot see past, because injecting this class is legal.
 *
 * @since      4.0.0
 */
final class CurrentUser
{
    public function __construct(private readonly Context $context) {}

    /**
     * The user as of this call.
     *
     * @return     User|ISecurityUser
     * @since      4.0.0
     */
    public function get()
    {
        return $this->context->getUser();
    }

    /**
     * Whether this request's user has authenticated.
     *
     * False for a user that does not implement {@see ISecurityUser} at all -- an application with
     * no security layer has no authenticated users rather than an unanswerable question.
     *
     * @since      4.0.0
     */
    public function isAuthenticated(): bool
    {
        $user = $this->get();

        return $user instanceof ISecurityUser && $user->isAuthenticated();
    }
}
