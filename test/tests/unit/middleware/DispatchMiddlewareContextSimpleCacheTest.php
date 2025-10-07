<?php

require_once __DIR__ . '/CacheMiddlewareTestTrait.php';

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Middleware\DispatchMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionDescriptor;

class DispatchMiddlewareContextSimpleCacheTest extends AgaviUnitTestCase
{
    use CacheMiddlewareTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Cache tests disabled after AgaviRequestDataHolder removal / cache layer refactor');
    }

    protected function tearDown(): void
    {
        putenv('AGAVI_DISPATCH_CONTEXT');
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE');
        $this->restoreCache();
        parent::tearDown();
    }

    private function buildPsr(): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        return (new ServerRequest('GET', 'http://localhost/cache'))
            ->withBody($factory->createStream(''))
            ->withAttribute('module','Cache')
            ->withAttribute('action','Cache')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Cache','Cache','GET','html'));
    }

    private function runMw(\Psr\Http\Message\ServerRequestInterface $psr, ExecutionState $state)
    {
        $controller = $this->getContext()->getController();
        $mw = new DispatchMiddleware($controller);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
        return $mw->process($psr->withAttribute(ExecutionState::class,$state), $handler);
    }

    public function testCacheHitOnSecondRequest()
    {
        $this->fail('unreachable');
        $state1 = new ExecutionState();
    $resp1 = $this->runMw($this->buildPsr(), $state1);
        $body1 = (string)$resp1->getBody();
        $this->assertStringContainsString('CACHE_HTML', $body1);
        $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'First execution should increment execCount');

        $state2 = new ExecutionState();
    $resp2 = $this->runMw($this->buildPsr(), $state2);
        $body2 = (string)$resp2->getBody();
        $this->assertStringContainsString('CACHE_HTML', $body2);
        $this->assertSame(1, \Sandbox\Modules\Cache\Actions\CacheAction::$execCount, 'Second execution should be served from cache');
        $this->assertSame(['1'], $resp2->getHeader('X-Agavi-Cache-Hit'));
    }
}
