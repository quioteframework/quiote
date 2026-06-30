<?php
namespace Sandbox\Modules\Fingerprint\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviWebRequest ;

class FingerprintSecureAction extends AgaviAction
{
    public static int $execCount = 0;
    public static array $executions = [];
    #[\Override]
    public function isSimple(){ return true; }
    #[\Override]
    public function isSecure(){ return true; }
    #[\Override]
    public function isCacheable(?string $ot = null): bool { return true; }
    public function cacheTtlSeconds(?string $ot = null): ?int { return 120; }
    public function execute(AgaviWebRequest $rd){
        self::$execCount++;
        self::$executions[] = time();
        // Return logical view token; actual HTML comes from Success view class
        return 'Success';
    }
}
