<?php
namespace Agavi\Execution;

use Agavi\Action\AgaviAction;
use Agavi\Controller\AgaviController;

/**
 * Lightweight security checker mapping AgaviAction security methods to a decision enum.
 * Phase 1: only supports isSecure + credentials check via context user.
 */
class SecurityService
{
    public function __construct(private readonly AgaviController $controller) {}

    public function decide(AgaviAction $action): SecurityDecision
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
