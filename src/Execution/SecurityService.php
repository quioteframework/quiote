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
            try { @file_put_contents(sys_get_temp_dir().'/agavi_sec_debug.log', 'Unauth '.$user::class."\n", FILE_APPEND); } catch(\Throwable) {}
            return SecurityDecision::LoginForward; }
        $cred = $action->getCredentials();
        if($cred !== null && !$user->hasCredentials($cred)) { return SecurityDecision::SecureForward; }
    return SecurityDecision::Allow;
    }
}
