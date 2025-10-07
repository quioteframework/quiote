<?php

use PHPUnit\Framework\TestCase;
use Agavi\AgaviContext;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\MiddlewareCatalog;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\TimingMiddleware;
use Agavi\Middleware\TraceMiddleware;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Nyholm\Psr7\ServerRequest;

/**
 * Tests the slimmed context-only MiddlewarePipeline ordering & enable flags.
 * @runTestsInSeparateProcesses
 */
class MiddlewarePipelineContextTest extends TestCase
{
    protected function setUp(): void
    {
        MiddlewareCatalog::initialize([]); // reset to defaults each test (all enabled)
    }

    private function ctx(): AgaviContext
    { return AgaviContext::getInstance(); }

    private function buildAndGetOrder(MiddlewarePipeline $p): array
    {
        $p->handle(new ServerRequest('GET', '/test'));
        return $p->debugStack();
    }

    public function testBaselineOrderingStartsWithErrorHandlingAndHasTerminal()
    {
        $pipeline = new MiddlewarePipeline($this->ctx());
    $order = $this->buildAndGetOrder($pipeline);
        $this->assertSame(ErrorHandlingMiddleware::class, $order[0]);
        $this->assertEquals('__TERMINAL__', end($order));
        // Ensure relative ordering: Timing before Trace when enabled
        $timingIdx = array_search(TimingMiddleware::class, $order, true);
        $traceIdx = array_search(TraceMiddleware::class, $order, true);
        $this->assertIsInt($timingIdx);
        $this->assertIsInt($traceIdx);
        $this->assertLessThan($traceIdx, $timingIdx, 'Timing should appear before Trace');
    }

    public function testDisableTimingRemovesItButKeepsTrace()
    {
        MiddlewareCatalog::initialize([
            TimingMiddleware::class => false,
        ]);
        $pipeline = new MiddlewarePipeline($this->ctx());
    $order = $this->buildAndGetOrder($pipeline);
        $this->assertNotContains(TimingMiddleware::class, $order);
        $this->assertContains(TraceMiddleware::class, $order);
    }

    public function testDisableTraceRemovesItButKeepsTiming()
    {
        MiddlewareCatalog::initialize([
            TraceMiddleware::class => false,
        ]);
        $pipeline = new MiddlewarePipeline($this->ctx());
    $order = $this->buildAndGetOrder($pipeline);
        $this->assertContains(TimingMiddleware::class, $order);
        $this->assertNotContains(TraceMiddleware::class, $order);
    }

    public function testDisableBothTimingAndTrace()
    {
        MiddlewareCatalog::initialize([
            TimingMiddleware::class => false,
            TraceMiddleware::class => false,
        ]);
        $pipeline = new MiddlewarePipeline($this->ctx());
    $order = $this->buildAndGetOrder($pipeline);
        $this->assertNotContains(TimingMiddleware::class, $order);
        $this->assertNotContains(TraceMiddleware::class, $order);
    }

    public function testDisableAllOptionalRemovesExecutionTimeTimingTrace()
    {
        MiddlewareCatalog::initialize([
            TimingMiddleware::class => false,
            TraceMiddleware::class => false,
            ExecutionTimeMiddleware::class => false,
        ]);
        $pipeline = new MiddlewarePipeline($this->ctx());
    $order = $this->buildAndGetOrder($pipeline);
        $this->assertNotContains(TimingMiddleware::class, $order);
        $this->assertNotContains(TraceMiddleware::class, $order);
        $this->assertNotContains(ExecutionTimeMiddleware::class, $order);
    }
}
