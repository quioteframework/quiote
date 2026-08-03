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

        $seed = CacheManager::getNamespaceVersion('avmod.Foo');

        // Another worker process invalidates the module: the bumped version goes
        // straight into the shared backend, not into this process's memo.
        CacheManager::getCache()->set(CacheManager::key('nsver', 'avmod.Foo'), $seed + 6);

        $context->reset();

        $this->assertSame(
            $seed + 6,
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
        $seed = CacheManager::getNamespaceVersion('avmod.Bar');

        // A concurrent bump landing mid-request must not be picked up partway
        // through: cache keys have to stay stable for the duration of a request.
        CacheManager::getCache()->set(CacheManager::key('nsver', 'avmod.Bar'), $seed + 8);

        $this->assertSame($seed, CacheManager::getNamespaceVersion('avmod.Bar'));
    }

    public function testBumpIsVisibleImmediatelyInTheBumpingRequest(): void
    {
        $seed = CacheManager::getNamespaceVersion('avmod.Baz');

        $bumped = CacheManager::bumpNamespace('avmod.Baz');

        $this->assertGreaterThan($seed, $bumped, 'a bump must strictly increase the version');
        $this->assertSame($bumped, CacheManager::getNamespaceVersion('avmod.Baz'));
    }

    public function testRepeatedBumpsWithinOneMillisecondStillIncreaseStrictly(): void
    {
        $previous = CacheManager::getNamespaceVersion('avmod.Rapid');
        for ($i = 0; $i < 5; $i++) {
            $bumped = CacheManager::bumpNamespace('avmod.Rapid');
            $this->assertGreaterThan($previous, $bumped);
            $previous = $bumped;
        }
    }

    /**
     * An evicted version entry must never re-issue a version this namespace has
     * already used: the entries written under that version are separate keys that
     * can still be live, so restarting the counter would resurrect content an
     * invalidation had already retired.
     */
    public function testAnEvictedVersionEntryReseedsAboveEveryVersionAlreadyIssued(): void
    {
        $before = CacheManager::getNamespaceVersion('avmod.Evicted');
        $highWaterMark = CacheManager::bumpNamespace('avmod.Evicted');

        // Let the millisecond clock advance past the bump, so this asserts the
        // reseed genuinely tracks the clock rather than racing it.
        usleep(3000);

        // The backend drops the version key (APCu memory pressure, Redis maxmemory)
        // while entries keyed by the old versions survive.
        CacheManager::getCache()->delete(CacheManager::key('nsver', 'avmod.Evicted'));
        CacheManager::resetRequestState();

        $reseeded = CacheManager::getNamespaceVersion('avmod.Evicted');

        $this->assertGreaterThan(
            $highWaterMark,
            $reseeded,
            'a reseeded namespace must land above every version it previously issued',
        );
        $this->assertGreaterThan($before, $reseeded);
    }
}
