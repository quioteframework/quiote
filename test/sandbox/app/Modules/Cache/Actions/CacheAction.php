<?php
namespace Sandbox\Modules\Cache\Actions;

use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviWebRequest ;

class CacheAction extends AgaviAction
{
    /**
     * Legacy compatibility: some legacy code paths expected an injected $container.
     * Under container-less execution this will remain null.
     */
    protected $container = null;
    public static int $execCount = 0;
    public function isSimple(){ return true; }
    public function isCacheable(?string $outputType = null): bool { return true; }
    public function getDefaultViewName(){ return 'Success'; }
    public function execute(AgaviWebRequest $rd){
        self::$execCount++;
    // Always set attribute now that ViewFactoryTest expects snapshot to include 'foo'.
    $this->setAttribute('foo','bar');
        return 'Success';
    }
}
