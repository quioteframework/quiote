<?php
namespace Sandbox\Modules\Cache\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviWebRequest ;

class CacheComplexAction extends AgaviAction
{
    public static int $execCount = 0;
    private static bool $failValidation = false;
    private static bool $requireAuth = false;
    private static bool $requireCred = false;

    public static function configure(bool $failValidation=false,bool $requireAuth=false,bool $requireCred=false): void {
        self::$failValidation = $failValidation; self::$requireAuth=$requireAuth; self::$requireCred=$requireCred; }

    public function isSimple(){ return false; }
    public function isSecure(){ return self::$requireAuth || self::$requireCred; }
    public function getCredentials(){ return self::$requireCred ? 'complex_cred' : null; }

    public function validate(AgaviWebRequest $rd) {
        if($rd->hasParameter('fail') && $rd->getParameter('fail')) { return false; }
        return !self::$failValidation; }

    public function handleError(AgaviWebRequest $rd) { return 'Error'; }

    public function getDefaultViewName(){ return 'Success'; }
    public function execute(AgaviWebRequest $rd){ self::$execCount++; return 'Success'; }
    public function isCacheable(?string $ot = null): bool { return true; }
    public function cacheTtlSeconds(?string $ot = null): ?int { return 60; }
}
