<?php
namespace Sandbox\Modules\Cache\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest ;

class CacheAction extends Action
{
    /**
     * Legacy compatibility: some legacy code paths expected an injected $container.
     * Under container-less execution this will remain null.
     */
    protected $container = null;
    public static int $execCount = 0;
    // Deliberately NOT isSimple(): isSimple() means "skip execute*() entirely,
    // render getDefaultViewName() directly" (Agavi heritage, commit f166330f4).
    // This fixture exists to exercise action/slot caching around a real
    // execute() call, so it must go through the normal (non-simple) path.
    #[\Override]
    public function isCacheable(?string $outputType = null): bool { return true; }
    #[\Override]
    public function getDefaultViewName(){ return 'Success'; }
    public function execute(WebRequest $rd){
        self::$execCount++;
    // Always set attribute now that ViewFactoryTest expects snapshot to include 'foo'.
    $this->setAttribute('foo','bar');
        return 'Success';
    }
}
