<?php
namespace Quiote\Execution;

use Quiote\Action\Action;
use Quiote\Controller\Controller;
use Quiote\User\ISecurityUser;

/**
 * Lightweight security checker mapping Action security methods to a decision enum.
 * Currently only supports isSecure + credentials check via context user.
 */
class SecurityService
{
    public function __construct(private readonly Controller $controller) {}

    public function decide(Action $action): SecurityDecision
    {
        if(!$action->isSecure()) { return SecurityDecision::Allow; }
        $user = $this->controller->getContext()->getUser();
        // Context::getUser() is declared User|ISecurityUser; a plain User carries no
        // authentication/credential capability at all, so a secure action guarded by
        // one must be treated as unauthenticated rather than fatal-erroring at runtime.
        if(!$user instanceof ISecurityUser || !$user->isAuthenticated()) {
            return SecurityDecision::LoginForward;
        }
        $cred = $action->getCredentials();
        if($cred !== null && !$user->hasCredentials($cred)) { return SecurityDecision::SecureForward; }
    return SecurityDecision::Allow;
    }
}
