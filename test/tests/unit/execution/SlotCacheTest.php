<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotDispatcher;
use Agavi\Execution\SlotStack;
use Agavi\Middleware\SlotMiddleware;
use Agavi\Cache\CacheManager;

class SlotCacheTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Agavi\Config\AgaviConfig::set('core.use_cache', true);
        // Enable slot cache for these tests
        putenv('AGAVI_SLOT_CACHE=1');
        // Reset PSR cache (memory + backend) so exec counts predictable
        CacheManager::reset();
        // Initialize module and action class
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
        $controller->createActionInstance('Cache','Cache');
    if(class_exists('Sandbox\\Modules\\Cache\\Actions\\CacheAction')) { \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0; }
    }

    protected function tearDown(): void
    {
        putenv('AGAVI_SLOT_CACHE'); // unset
        parent::tearDown();
    }

    public function testCacheHitPreventsSecondExecution()
    {
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
    if(class_exists('Sandbox\\Modules\\Cache\\Actions\\CacheAction')) { \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0; }
        $first = $dispatcher->dispatch($parent, 'Cache','Cache', ['k'=>'v']);
        $this->assertNotSame('', $first);
        $this->assertStringContainsString('CACHE_', $first);
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'First execution should increment exec count');
        // Second call with same parameters should be cache hit (early return, no extra execute)
        $second = $dispatcher->dispatch($parent, 'Cache','Cache', ['k'=>'v']);
        $this->assertSame($first, $second);
    $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Exec count should remain 1 due to cache hit');
    }

    public function testDifferentParametersProduceCacheMiss()
    {
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
    if(class_exists('Sandbox\\Modules\\Cache\\Actions\\CacheAction')) { \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0; }
    $dispatcher->dispatch($parent, 'Cache','Cache', ['p'=>'A']);
    $dispatcher->dispatch($parent, 'Cache','Cache', ['p'=>'B']);
    $this->assertSame(2, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Distinct parameter sets should bypass cache and execute again');
    }

    public function testCacheDisabledExecutesEachTime()
    {
        putenv('AGAVI_SLOT_CACHE='); // disable
        CacheManager::reset();
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
    if(class_exists('Sandbox\\Modules\\Cache\\Actions\\CacheAction')) { \Sandbox\Modules\Cache\Actions\CacheAction::$execCount = 0; }
        $dispatcher->dispatch($parent, 'Cache','Cache');
        $dispatcher->dispatch($parent, 'Cache','Cache');
    $this->assertSame(2, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Without cache flag executions should not be short-circuited');
    }
}
