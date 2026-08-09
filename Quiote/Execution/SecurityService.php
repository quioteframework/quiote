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

    /**
     * Decides how the controller should proceed with a security-guarded action.
     *
     * An action that does not declare itself secure is allowed outright. Otherwise the user is
     * resolved from the controller's context container: anything that is not an ISecurityUser,
     * or an ISecurityUser that is not authenticated, yields a login forward. An authenticated
     * user that lacks the action's declared credentials yields a secure forward; one that has
     * them, or an action declaring no credentials, is allowed.
     */
    public function decide(Action $action): SecurityDecision
    {
        if(!$action->isSecure()) { return SecurityDecision::Allow; }
        $user = $this->controller->getContext()->getContainer()->get(\Quiote\User\User::class);
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
