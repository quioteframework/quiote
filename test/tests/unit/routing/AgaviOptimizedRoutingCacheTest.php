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
        parent::setUp();
        AgaviRouteCacheManager::clear();
        AgaviRoutingPerformanceMonitor::getResetInstance()->reset();
        $this->routing = new AgaviOptimizedWebRouting();
        $this->routing->initialize($this->getContext(), ['enabled' => true, 'optimizations' => ['force_optimized' => true]]);
        $this->routing->startup();
        // Minimal route set
        $this->routing->importRoutes([]);
        $this->routing->addRoute('^/$', [ 'name' => 'index', 'module' => 'Default', 'action' => 'Index' ]);
        $this->routing->addRoute('^/nocache$', [ 'name' => 'nocache', 'module' => 'Default', 'action' => 'Error404' ]); // will produce 404 container? not cached
        // Re-run analyzer for new route set
        $ref = new \ReflectionClass(AgaviOptimizedWebRouting::class);
        $m = $ref->getMethod('analyzeRouteComplexity');
        $m->setAccessible(true);
        $m->invoke($this->routing);
    }

    public function testCacheHitAndMiss()
    {
        /** @var \Agavi\Request\AgaviWebRequest $rq */
        $rq = $this->getContext()->getRequest();
        $rq->setRequestUri('/');
        $c1 = $this->routing->execute();
        $statsAfterFirst = AgaviRouteCacheManager::getStats();
        $this->assertEquals(1, $statsAfterFirst['misses']);
        $this->assertEquals(0, $statsAfterFirst['hits']);
        $rq->setRequestUri('/');
        $c2 = $this->routing->execute();
        $statsAfterSecond = AgaviRouteCacheManager::getStats();
        $this->assertEquals(1, $statsAfterSecond['misses']);
        $this->assertEquals(1, $statsAfterSecond['hits']);
        $this->assertSame($c1->getModuleName(), $c2->getModuleName());
        $this->assertSame($c1->getActionName(), $c2->getActionName());
    }

    public function test404NotCached()
    {
        /** @var \Agavi\Request\AgaviWebRequest $rq */
        $rq = $this->getContext()->getRequest();
        $rq->setRequestUri('/does-not-exist');
        $c1 = $this->routing->execute();
        $this->assertEquals('Default', $c1->getModuleName());
        $this->assertEquals('Error404', $c1->getActionName());
        $statsAfterFirst = AgaviRouteCacheManager::getStats();
        $this->assertEquals(1, $statsAfterFirst['misses']);
        $rq->setRequestUri('/does-not-exist');
        $c2 = $this->routing->execute();
        $this->assertEquals('Default', $c2->getModuleName());
        $this->assertEquals('Error404', $c2->getActionName());
        $statsAfterSecond = AgaviRouteCacheManager::getStats();
        // second miss because 404 not cached
        $this->assertEquals(2, $statsAfterSecond['misses']);
        $this->assertEquals(0, $statsAfterSecond['hits']);
    }

    public function testParametersRestoredFromCache()
    {
        // route with param
        $this->routing->addRoute('^/id/(val:\\d+)$', [ 'name' => 'id_route', 'module' => 'Default', 'action' => 'Show' ]);
        $ref = new \ReflectionClass(AgaviOptimizedWebRouting::class);
        $m = $ref->getMethod('analyzeRouteComplexity');
        $m->setAccessible(true);
        $m->invoke($this->routing);
        /** @var \Agavi\Request\AgaviWebRequest $rq */
        $rq = $this->getContext()->getRequest();
        $rq->setRequestUri('/id/77');
        $this->routing->execute(); // populate cache
        $rq->setRequestUri('/id/77');
        $rd = $this->getContext()->getRequest()->getRequestData();
        $rd->setParameter('val', null); // clear to ensure restored
        $this->routing->execute(); // cache hit should restore
        $this->assertEquals('77', $rd->getParameter('val'));
    }
}
