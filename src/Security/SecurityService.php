<?php
namespace Agavi\Security;

use Agavi\Controller\AgaviExecutionContainer;
use Agavi\Controller\AgaviController;
use Agavi\Execution\ExecutionState;

/**
 * Adapter encapsulating legacy security logic (isSecure, credentials) so the
 * middleware can set ExecutionState->securityDecision without requiring the
 * full execution container mutation path.
 */
final class SecurityService
{
    public function __construct(private AgaviController $controller) {}

    /**
     * Derive a SecurityDecision for the given action descriptor via container (interim).
     * Returns tuple [SecurityDecision, ?AgaviExecutionContainer forwardContainer].
     */
    public function decide(AgaviExecutionContainer $container): array
    {
        try {
            $action = $container->getActionInstance();
        } catch(\Throwable) { return [SecurityDecision::ALLOW, null]; }
        if(!$action->isSecure()) { return [SecurityDecision::ALLOW, null]; }
        $user = $this->controller->getContext()->getUser();
        if(!$user->isAuthenticated()) {
            $login = $container->createSystemActionForwardContainer('login');
            $login->setSecurityForwarded(true);
            return [SecurityDecision::FORWARD_LOGIN, $login];
        }
        $cred = $action->getCredentials();
        if($cred !== null && !$user->hasCredentials($cred)) {
            $secure = $container->createSystemActionForwardContainer('secure');
            return [SecurityDecision::FORWARD_SECURE, $secure];
        }
        return [SecurityDecision::ALLOW, null];
    }
}
