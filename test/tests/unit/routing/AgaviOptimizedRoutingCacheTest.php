<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Routing\AgaviOptimizedWebRouting;
use Agavi\Routing\AgaviRouteCacheManager;
use Agavi\Routing\AgaviRoutingPerformanceMonitor;

class AgaviOptimizedRoutingCacheTest extends AgaviUnitTestCase
{
    private AgaviOptimizedWebRouting $routing;

    protected function setUp(): void
    {
    $this->markTestSkipped('AgaviOptimizedWebRouting removed (Symfony routing migration).');
    }

    public function testCacheHitAndMiss()
    {
    $this->fail('Skipped');
    }

    public function test404NotCached()
    {
    $this->fail('Skipped');
    }

    public function testParametersRestoredFromCache()
    {
    $this->fail('Skipped');
    }
}
