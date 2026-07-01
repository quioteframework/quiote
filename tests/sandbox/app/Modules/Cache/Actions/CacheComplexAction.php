<?php
namespace Sandbox\Modules\Cache\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

class CacheComplexAction extends Action
{
    public static int $execCount = 0;
    private static bool $failValidation = false;
    private static bool $requireAuth = false;
    private static bool $requireCred = false;

    public static function configure(bool $failValidation=false,bool $requireAuth=false,bool $requireCred=false): void {
        self::$failValidation = $failValidation; self::$requireAuth=$requireAuth; self::$requireCred=$requireCred; }

    #[\Override]
    public function isSimple(){ return false; }
    #[\Override]
    public function isSecure(){ return self::$requireAuth || self::$requireCred; }
    public function getCredentials(){ return self::$requireCred ? 'complex_cred' : null; }

    #[\Override]
    public function validate(WebRequest $rd) {
        if($rd->hasParameter('fail') && $rd->getParameter('fail')) { return false; }
        return !self::$failValidation; }

    #[\Override]
    public function handleError(WebRequest $rd) { return 'Error'; }

    #[\Override]
    public function getDefaultViewName(){ return 'Success'; }
    public function execute(WebRequest $rd){ self::$execCount++; return 'Success'; }
    #[\Override]
    public function isCacheable(?string $ot = null): bool { return true; }
    public function cacheTtlSeconds(?string $ot = null): ?int { return 60; }
}
