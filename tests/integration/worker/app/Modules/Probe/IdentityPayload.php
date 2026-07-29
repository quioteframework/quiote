<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe;

use Quiote\Action\Action;
use Quiote\User\RbacSecurityUser;
use Quiote\User\SecurityUser;

/**
 * Shared payload for the three identity probe endpoints, so /identity, /login
 * and /logout answer in exactly the same shape and a test can compare them
 * directly.
 *
 * They are separate routes rather than one endpoint taking `?login=1`: the
 * probe app runs the validation manager in strict mode, where reading a
 * parameter no action declared raises UnvalidatedParameterAccessException.
 */
trait IdentityPayload
{
    private function reportIdentity(Action $action): void
    {
        $context = $action->getContext();
        $user = $context?->getUser();

        $action->setAttribute('authenticated', $user instanceof SecurityUser && $user->isAuthenticated());
        $action->setAttribute('roles', $user instanceof RbacSecurityUser ? $user->getRoles() : []);
        $action->setAttribute('credentials', $user instanceof SecurityUser ? ($user->getCredentials() ?? []) : []);
        $action->setAttribute('display_name', $user?->getAttribute('display_name'));
    }
}
