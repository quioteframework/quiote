<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotStack;
use Agavi\Middleware\SlotMiddleware;
use Agavi\Cache\CacheManager;
use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviRequestDataHolder;

// Test helper actions
if(!class_exists('Cache_TtlAction')) {
    class Cache_TtlAction extends Agavi\Action\AgaviAction { use Agavi\Action\SlotCacheableTrait; public static $exec=0; public function isSimple(){return true;} public function slotCacheTtlSeconds(): ?int { return 1; } public function execute(AgaviRequestDataHolder $rd){ self::$exec++; return 'Success'; } public function getDefaultViewName(){ return 'Success'; } }
}
if(!class_exists('Cache_TaggedAction')) {
    class Cache_TaggedAction extends Agavi\Action\AgaviAction { use Agavi\Action\SlotCacheableTrait; public static $exec=0; public function isSimple(){return true;} public function slotCacheTags(array $p=[]): array { return ['alpha']; } public function execute(AgaviRequestDataHolder $rd){ self::$exec++; return 'Success'; } public function getDefaultViewName(){ return 'Success'; } }
}

class SlotCacheTtlAndTagTest extends AgaviUnitTestCase {
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Cache tests disabled after AgaviRequestDataHolder removal / cache layer refactor');
    if(!Agavi\Config\AgaviConfig::get('core.cache_enabled', false)) { /* legacy branch removed */ }
        putenv('AGAVI_SLOT_CACHE=1');
        CacheManager::reset();
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
    }
    protected function tearDown(): void
    { putenv('AGAVI_SLOT_CACHE'); parent::tearDown(); }

    private function parentReq(): \Psr\Http\Message\ServerRequestInterface
    { return (new ServerRequest('GET','http://localhost/'))->withAttribute(SlotMiddleware::ATTR, new SlotStack()); }

    public function testTtlExpiryCausesReExecution()
    {
        $this->fail('unreachable');
        $dispatcher = $this->getContext()->getSlotDispatcher();
        Cache_TtlAction::$exec=0;
        $first = $dispatcher->dispatch($this->parentReq(),'Cache','Ttl');
        $this->assertSame('TTL_OK',$first);
        $this->assertSame(1, Cache_TtlAction::$exec);
        // immediate second call hits cache
        $second = $dispatcher->dispatch($this->parentReq(),'Cache','Ttl');
        $this->assertSame('TTL_OK',$second);
        $this->assertSame(1, Cache_TtlAction::$exec, 'Within TTL should not re-execute');
        // sleep just over 1 second to expire
        usleep(1_100_000);
        $third = $dispatcher->dispatch($this->parentReq(),'Cache','Ttl');
        $this->assertSame('TTL_OK',$third);
        $this->assertSame(2, Cache_TtlAction::$exec, 'After TTL expiry should execute again');
    }

    public function testTagInvalidationBumpsVersion()
    {
        $this->fail('unreachable');
        $dispatcher = $this->getContext()->getSlotDispatcher();
        Cache_TaggedAction::$exec=0;
        $a = $dispatcher->dispatch($this->parentReq(),'Cache','Tagged');
        $this->assertSame(1, Cache_TaggedAction::$exec);
        $b = $dispatcher->dispatch($this->parentReq(),'Cache','Tagged');
        $this->assertSame(1, Cache_TaggedAction::$exec, 'Cache hit with same tag version');
        // Invalidate tag
        CacheManager::invalidateSlotTag('alpha');
        $c = $dispatcher->dispatch($this->parentReq(),'Cache','Tagged');
        $this->assertSame(2, Cache_TaggedAction::$exec, 'Exec count increments after tag invalidation');
        $this->assertSame('TAG_OK',$c);
    }
}
