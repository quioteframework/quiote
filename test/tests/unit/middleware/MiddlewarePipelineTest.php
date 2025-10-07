<?php

use PHPUnit\Framework\TestCase;
use Agavi\AgaviContext;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\MiddlewareCatalog;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;

require_once __DIR__ . '/OutputBufferNormalizer.php';

/**
 * @runTestsInSeparateProcesses
 */
class MiddlewarePipelineTest extends TestCase
{
    private int $initialObLevel;
    protected function setUp(): void
    {
        // Minimal bootstrapping of context (reuse existing bootstrap if available)
        if (!class_exists(AgaviContext::class)) {
            $this->markTestSkipped('AgaviContext not available');
        }
        // Ensure catalog default (all enabled) each test
        MiddlewareCatalog::initialize([]);
        $this->initialObLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        // Drain any extra buffers opened by framework code during the test
        while (ob_get_level() > $this->initialObLevel) {
            try {
                ob_end_clean();
            } catch (\Throwable) {
                break;
            }
        }
    }

    private function makeContext(): AgaviContext
    {
        // Use default context profile
        return AgaviContext::getInstance();
    }

    public function testOrderingBaselineIncludesExecutionTime()
    {
        $ctx = $this->makeContext();
        $pipeline = new MiddlewarePipeline($ctx);
        $normalizer = new OutputBufferNormalizer();
        $pipeline->handle(new ServerRequest('GET', '/'));
        $normalizer->normalize();
        $end = ob_get_level();
        $order = $pipeline->debugStack();
        $this->assertSame(ErrorHandlingMiddleware::class, $order[0]);
        $this->assertContains(ExecutionTimeMiddleware::class, $order);
        $this->assertEquals('__TERMINAL__', end($order));
        $this->assertSame($normalizer->startLevel(), $end);
    }

    public function testExecutionTimeDisabledRemovedFromOrder()
    {
        MiddlewareCatalog::initialize([ExecutionTimeMiddleware::class => false]);
        $ctx = $this->makeContext();
        $pipeline = new MiddlewarePipeline($ctx);
        $normalizer = new OutputBufferNormalizer();
        $pipeline->handle(new ServerRequest('GET', '/'));
        $normalizer->normalize();
        $end = ob_get_level();
        $order = $pipeline->debugStack();
        $this->assertNotContains(ExecutionTimeMiddleware::class, $order);
        $this->assertSame($normalizer->startLevel(), $end);
    }

    public function testResetRebuildsWithToggledExecutionTime()
    {
        $ctx = $this->makeContext();
        $pipeline = new MiddlewarePipeline($ctx);
        $normalizer1 = new OutputBufferNormalizer();
        $pipeline->handle(new ServerRequest('GET', '/'));
        $normalizer1->normalize();
        $end1 = ob_get_level();
        $with = $pipeline->debugStack();
        $this->assertContains(ExecutionTimeMiddleware::class, $with);
        MiddlewareCatalog::initialize([ExecutionTimeMiddleware::class => false]);
        $pipeline->reset();
        $normalizer2 = new OutputBufferNormalizer();
        $pipeline->handle(new ServerRequest('GET', '/toggle'));
        $normalizer2->normalize();
        $without = $pipeline->debugStack();
        $this->assertNotContains(ExecutionTimeMiddleware::class, $without);
        $end2 = ob_get_level();
        $this->assertSame($normalizer1->startLevel(), $end1);
        $this->assertSame($normalizer2->startLevel(), $end2);
    }

    public function testErrorHandlingCatchesException()
    {
        $ctx = $this->makeContext();
        $pipeline = new MiddlewarePipeline($ctx);
        $response = $pipeline->handle(new ServerRequest('GET', '/error-test'));
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }
}
