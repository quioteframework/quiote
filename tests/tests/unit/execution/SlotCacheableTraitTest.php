<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Middleware\SlotMiddleware;
use Quiote\Cache\CacheManager;
use Sandbox\Modules\Cache\Actions\TtlAction;
use Sandbox\Modules\Cache\Actions\TaggedAction;
use Quiote\Execution\SlotStack;

/**
 * SlotCacheableTrait lets an action customize the TTL and cache-invalidation
 * tags used by the slot cache. Slot caching itself is a global on/off switch
 * (core.use_cache + QUIOTE_SLOT_CACHE) independent of any per-action opt-in;
 * the trait only tunes how long/under-what-tags an already-cached slot's
 * rendered output is kept.
 */
class SlotCacheableTraitTest extends UnitTestCase
{
    /** @var mixed Original core.use_cache value, restored in tearDown(). */
    private $origUseCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origUseCache = \Quiote\Config\Config::getBool('core.use_cache', false);
        \Quiote\Config\Config::set('core.use_cache', true);
        putenv('QUIOTE_SLOT_CACHE=1');
        CacheManager::reset();
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
        $controller->createActionInstance('Cache', 'Ttl');
        $controller->createActionInstance('Cache', 'Tagged');
        TtlAction::$execCount = 0;
        TtlAction::$ttlSeconds = null;
        TaggedAction::$execCount = 0;
    }

    protected function tearDown(): void
    {
        putenv('QUIOTE_SLOT_CACHE');
        if ($this->origUseCache === null) {
            \Quiote\Config\Config::set('core.use_cache', false);
        } else {
            \Quiote\Config\Config::set('core.use_cache', $this->origUseCache);
        }
        CacheManager::reset();
        parent::tearDown();
    }

    private function newParentRequest(): ServerRequest
    {
        return (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
    }

    public function testSlotCacheTtlSecondsControlsExpiry(): void
    {
        TtlAction::$ttlSeconds = 1;
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $parent = $this->newParentRequest();

        $first = $dispatcher->dispatch($parent, 'Cache', 'Ttl');
        $this->assertSame('TTL_OK', $first);
        $this->assertSame(1, TtlAction::$execCount, 'First dispatch should execute the action');

        // Immediately re-dispatching should hit the cache (TTL not yet elapsed).
        $second = $dispatcher->dispatch($parent, 'Cache', 'Ttl');
        $this->assertSame(1, TtlAction::$execCount, 'Cache hit should not re-execute the action');

        // After the configured TTL elapses, the entry expires and the action runs again.
        sleep(2);
        $third = $dispatcher->dispatch($parent, 'Cache', 'Ttl');
        $this->assertSame('TTL_OK', $third);
        $this->assertSame(2, TtlAction::$execCount, 'Expired cache entry should trigger re-execution');
    }

    public function testSlotCacheTtlSecondsDefaultsToBackendTtl(): void
    {
        // A null TTL (the trait's default) means "no explicit TTL" - the entry
        // is still cached (subject to the backend's own default expiry), it's
        // just not given a bespoke short/long lifetime by the action.
        TtlAction::$ttlSeconds = null;
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $parent = $this->newParentRequest();

        $dispatcher->dispatch($parent, 'Cache', 'Ttl');
        $dispatcher->dispatch($parent, 'Cache', 'Ttl');
        $this->assertSame(1, TtlAction::$execCount, 'Second dispatch should still be a cache hit');
    }

    public function testSlotCacheTagsIsolatesCacheKeysByTag(): void
    {
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $parent = $this->newParentRequest();

        $groupA = $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'A']);
        $groupB = $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'B']);
        $this->assertSame('TAG_OK', $groupA);
        $this->assertSame('TAG_OK', $groupB);
        $this->assertSame(2, TaggedAction::$execCount, 'Distinct tag groups should execute independently');

        // Re-dispatching either group should now be a cache hit.
        $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'A']);
        $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'B']);
        $this->assertSame(2, TaggedAction::$execCount, 'Both groups should remain cached');
    }

    public function testBumpingSlotCacheTagInvalidatesOnlyThatTag(): void
    {
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $parent = $this->newParentRequest();

        $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'A']);
        $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'B']);
        $this->assertSame(2, TaggedAction::$execCount);

        // Bumping group A's tag namespace invalidates only slot cache entries tagged group:A.
        CacheManager::bumpNamespace('slot_tag:group:A');

        $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'A']);
        $this->assertSame(3, TaggedAction::$execCount, 'Tag bump should invalidate group A and force re-execution');

        $dispatcher->dispatch($parent, 'Cache', 'Tagged', ['group' => 'B']);
        $this->assertSame(3, TaggedAction::$execCount, 'Group B should be unaffected by group A tag bump');
    }
}
