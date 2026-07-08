<?php

use Quiote\Action\Action;
use Quiote\Action\Traits\CacheableActionTrait;
use PHPUnit\Framework\TestCase;

/**
 * CacheableActionTrait is an opt-in convenience: Action already defaults to
 * isCacheable()=false / cacheTtlSeconds()=null, so an action wanting the
 * opposite defaults (cacheable, 5 minute TTL) can `use CacheableActionTrait;`
 * instead of overriding both methods by hand.
 */
class CacheableActionTraitTest extends TestCase
{
    public function testDefaultActionIsNotCacheable(): void
    {
        $action = new class extends Action {
            public function execute(?\Quiote\Request\WebRequest $rd = null): void {}
        };
        $this->assertFalse($action->isCacheable());
        $this->assertNull($action->cacheTtlSeconds());
    }

    public function testTraitMarksActionCacheableWithDefaultTtl(): void
    {
        $action = new class extends Action {
            use CacheableActionTrait;
            public function execute(?\Quiote\Request\WebRequest $rd = null): void {}
        };
        $this->assertTrue($action->isCacheable());
        $this->assertTrue($action->isCacheable('json'));
        $this->assertSame(300, $action->cacheTtlSeconds());
        $this->assertSame(300, $action->cacheTtlSeconds('json'));
    }

    public function testTraitDefaultsCanStillBeOverridden(): void
    {
        $action = new class extends Action {
            use CacheableActionTrait;
            public function execute(?\Quiote\Request\WebRequest $rd = null): void {}
            #[\Override]
            public function cacheTtlSeconds(?string $outputType = null): int
            {
                // Trait methods are copied into the class, not inherited, so the
                // override fully replaces the trait's version - it can't delegate
                // to it via parent::. Reimplement the fallback explicitly.
                return $outputType === 'json' ? 60 : 300;
            }
        };
        $this->assertTrue($action->isCacheable());
        $this->assertSame(60, $action->cacheTtlSeconds('json'));
        $this->assertSame(300, $action->cacheTtlSeconds('html'));
    }
}
