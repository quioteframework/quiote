<?php

use Quiote\Cache\CacheManager;
use Quiote\Testing\UnitTestCase;

/**
 * The namespace-version memo must not outlive the request.
 *
 * getNamespaceVersion() memoizes, and invalidateModule()/invalidateAction()/
 * invalidateSlotTag() work by bumping a version in the shared cache backend. If
 * the memo survives the request boundary it becomes a per-process memo, and a
 * bump performed by any other worker process is never observed -- this process
 * goes on serving output under the stale version for the rest of its life.
 */
class CacheNamespaceVersionBoundaryTest extends UnitTestCase
{
    #[\Override]
    public function setUp(): void
    {
        CacheManager::reset();
    }

    #[\Override]
    public function tearDown(): void
    {
        CacheManager::reset();
    }

    public function testAVersionBumpedElsewhereIsObservedAfterTheRequestBoundary(): void
    {
        $context = $this->getContext();

        $this->assertSame(1, CacheManager::getNamespaceVersion('avmod.Foo'));

        // Another worker process invalidates the module: the bumped version goes
        // straight into the shared backend, not into this process's memo.
        CacheManager::getCache()->set(CacheManager::key('nsver', 'avmod.Foo'), 7);

        $context->reset();

        $this->assertSame(
            7,
            CacheManager::getNamespaceVersion('avmod.Foo'),
            'the request boundary must drop the memo so the shared version is re-read',
        );
    }

    public function testResetRequestStateKeepsTheConfiguredBackend(): void
    {
        $spy = new \Quiote\Test\Cache\Psr16KeyRecordingCache();
        CacheManager::setCache($spy, 'spy');

        CacheManager::resetRequestState();

        $this->assertSame('spy', CacheManager::getBackend(), 'the pool must survive the boundary');
        $this->assertSame($spy, CacheManager::getCache());
    }

    public function testMemoStillServesRepeatedReadsWithinOneRequest(): void
    {
        $this->assertSame(1, CacheManager::getNamespaceVersion('avmod.Bar'));

        // A concurrent bump landing mid-request must not be picked up partway
        // through: cache keys have to stay stable for the duration of a request.
        CacheManager::getCache()->set(CacheManager::key('nsver', 'avmod.Bar'), 9);

        $this->assertSame(1, CacheManager::getNamespaceVersion('avmod.Bar'));
    }

    public function testBumpIsVisibleImmediatelyInTheBumpingRequest(): void
    {
        $this->assertSame(1, CacheManager::getNamespaceVersion('avmod.Baz'));
        $this->assertSame(2, CacheManager::bumpNamespace('avmod.Baz'));
        $this->assertSame(2, CacheManager::getNamespaceVersion('avmod.Baz'));
    }
}
