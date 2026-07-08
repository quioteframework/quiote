<?php
namespace Sandbox\Modules\Fingerprint\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

class FingerprintSecureAction extends Action
{
    public static int $execCount = 0;
    /** @var list<int> */
    public static array $executions = [];
    #[\Override]
    public function isSimple(){ return true; }
    #[\Override]
    public function isSecure(){ return true; }
    #[\Override]
    public function isCacheable(?string $ot = null): bool { return true; }
    public function cacheTtlSeconds(?string $ot = null): ?int { return 120; }
    public function execute(WebRequest $rd): string{
        self::$execCount++;
        self::$executions[] = time();
        // Return logical view token; actual HTML comes from Success view class
        return 'Success';
    }
}
