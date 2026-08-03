<?php

use PHPUnit\Framework\TestCase;
use Quiote\Action\Action;
use Quiote\Cache\ActionViewCache;
use Quiote\Config\Config;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionCacheHelper;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Request\WebRequest;
use Quiote\Session\SessionBagInterface;

/**
 * A secure action's cached output belongs to one identity and must never be
 * served to another.
 *
 * Two independent ways that used to break:
 *  - the partition key reduced to sha1('auth:1') for every authenticated user,
 *    so all of them shared one entry;
 *  - a partitioned read fell back to the unpartitioned entry on a miss, so a
 *    cold cache was answered with whatever content was in the shared slot.
 */
class ActionCacheUserPartitionTest extends TestCase
{
    private ActionViewCache $cache;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('core.cache_enabled', true);
        $adapter = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        $this->cache = new ActionViewCache(new \Symfony\Component\Cache\Psr16Cache($adapter), 300);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::remove('core.cache_enabled');
        parent::tearDown();
    }

    private function descriptor(): ActionDescriptor
    {
        return new ActionDescriptor('Secure', 'Dashboard', 'GET', 'html', true);
    }

    public function testAPartitionedReadDoesNotFallBackToTheSharedEntry(): void
    {
        $desc = $this->descriptor();
        $state = new ExecutionState();

        // Something populated the unpartitioned slot.
        ActionCacheHelper::store($this->cache, $desc, $state, 'SHARED', [], true, null, null, null);

        // A user whose own partition is cold must get a miss, not the shared entry.
        $this->assertNull(
            ActionCacheHelper::read($this->cache, $desc, 'fp-user-a', null),
            'a cold partition must miss rather than fall back to the shared entry',
        );
    }

    public function testOneUsersCachedOutputIsNotReadableByAnother(): void
    {
        $desc = $this->descriptor();
        $state = new ExecutionState();

        ActionCacheHelper::store($this->cache, $desc, $state, "user A's balance", [], true, null, 'fp-user-a', null);

        $this->assertNull(ActionCacheHelper::read($this->cache, $desc, 'fp-user-b', null));
        $payloadA = ActionCacheHelper::read($this->cache, $desc, 'fp-user-a', null);
        $this->assertNotNull($payloadA);
        $this->assertSame("user A's balance", $payloadA['response_content']);
    }

    /**
     * The derivation is private, and deliberately so -- but it is the whole
     * security property, so it is asserted directly rather than inferred from a
     * cache hit/miss that a dozen unrelated conditions also gate.
     */
    private function fingerprintFor(Action $action, string $sessionId): string|false|null
    {
        $bag = $this->createStub(SessionBagInterface::class);
        $bag->method('getId')->willReturn($sessionId);

        $context = $this->createStub(\Quiote\Context::class);
        $context->method('getSessionBag')->willReturn($bag);

        // Controller::getContext() is final, so the backing property is set
        // directly rather than stubbed.
        $controller = $this->createStub(Controller::class);
        $contextProperty = new ReflectionProperty(Controller::class, 'context');
        $contextProperty->setValue($controller, $context);

        $middleware = new DispatchMiddleware($controller);
        $method = new ReflectionMethod($middleware, 'computeUserFingerprint');

        /** @var string|false|null $result */
        $result = $method->invoke($middleware, $action, 'html');
        return $result;
    }

    public function testTwoSessionsGetDifferentPartitions(): void
    {
        $action = new PartitionSecureCacheableAction();

        $a = $this->fingerprintFor($action, 'session-aaaaaaaaaaaaaaaa');
        $b = $this->fingerprintFor($action, 'session-bbbbbbbbbbbbbbbb');

        $this->assertIsString($a);
        $this->assertIsString($b);
        $this->assertNotSame($a, $b, 'two sessions must not share a cache partition');
    }

    public function testTheSameSessionGetsAStablePartition(): void
    {
        $action = new PartitionSecureCacheableAction();

        $this->assertSame(
            $this->fingerprintFor($action, 'session-aaaaaaaaaaaaaaaa'),
            $this->fingerprintFor($action, 'session-aaaaaaaaaaaaaaaa'),
            'a stable partition is what makes the cache useful at all',
        );
    }

    public function testThePartitionKeyDoesNotLeakTheSessionId(): void
    {
        $key = $this->fingerprintFor(new PartitionSecureCacheableAction(), 'session-aaaaaaaaaaaaaaaa');

        $this->assertIsString($key);
        $this->assertStringNotContainsString('session-aaaaaaaaaaaaaaaa', $key);
    }

    public function testNoSessionMeansCachingIsRefusedRatherThanShared(): void
    {
        $this->assertFalse(
            $this->fingerprintFor(new PartitionSecureCacheableAction(), ''),
            'without an identity handle the cache must be skipped, not shared',
        );
    }

    public function testANonSecureActionStillSharesOneEntry(): void
    {
        $this->assertNull(
            $this->fingerprintFor(new PartitionPublicCacheableAction(), 'session-aaaaaaaaaaaaaaaa'),
            'a non-secure action has no identity to vary on',
        );
    }

    public function testAnActionMayOptOutOfPartitioning(): void
    {
        $this->assertNull(
            $this->fingerprintFor(new PartitionSecureSharedAction(), 'session-aaaaaaaaaaaaaaaa'),
            'cacheVaryByUser() === false is the deliberate opt-out',
        );
    }
}

class PartitionSecureCacheableAction extends Action
{
    #[\Override] public function isSecure() { return true; }
    #[\Override] public function isCacheable(?string $outputType = null): bool { return true; }
}

class PartitionPublicCacheableAction extends Action
{
    #[\Override] public function isSecure() { return false; }
    #[\Override] public function isCacheable(?string $outputType = null): bool { return true; }
}

class PartitionSecureSharedAction extends Action
{
    #[\Override] public function isSecure() { return true; }
    #[\Override] public function isCacheable(?string $outputType = null): bool { return true; }
    #[\Override] public function cacheVaryByUser(?string $outputType = null): bool { return false; }
}
