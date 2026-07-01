<?php
namespace Quiote\Execution;

use Quiote\Action\Action;
use Quiote\Controller\Controller;

/**
 * Lightweight security checker mapping Action security methods to a decision enum.
 * Phase 1: only supports isSecure + credentials check via context user.
 */
class SecurityService
{
    public function __construct(private readonly Controller $controller) {}

    public function decide(Action $action): SecurityDecision
    {
        if(!$action->isSecure()) { return SecurityDecision::Allow; }
        $user = $this->controller->getContext()->getUser();
        if(!$user->isAuthenticated()) {
            return SecurityDecision::LoginForward; }
        $cred = $action->getCredentials();
        if($cred !== null && !$user->hasCredentials($cred)) { return SecurityDecision::SecureForward; }
    return SecurityDecision::Allow;
    }
}
