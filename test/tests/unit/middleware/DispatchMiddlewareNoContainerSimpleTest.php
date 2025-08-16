<?php

use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Execution\ActionDescriptor;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ExecutionState;

class DispatchMiddlewareNoContainerSimpleTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    \Agavi\Cache\CacheManager::reset();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE=1');
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE_NOCONTAINER=1');
        $this->getContext()->getController()->initializeModule('Cache');
    // Ensure action view cache entry removed using ActionViewCache API
    try { (new \Agavi\Cache\ActionViewCache(\Agavi\Cache\CacheManager::getCache()))->delete('Cache','Cache','html'); } catch(\Throwable) {}
    }

    private function buildRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/cache'),
            'GET',
            $factory->createStream(''),
            [], [], [], [], [], []
        );
        return $psr
            ->withAttribute('module','Cache')
            ->withAttribute('action','Cache')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Cache','Cache','GET','html'));
    }

    public function testSimpleNoContainerHeaderAndAttribute()
    {
        $controller = $this->getContext()->getController();
        $mw = new DispatchMiddleware($controller);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
        $req = $this->buildRequest()->withAttribute(ExecutionState::class,new ExecutionState(false,true,null,null,[],false));
        $resp = $mw->process($req,$handler);
        $this->assertFalse($resp->hasHeader('X-Agavi-Cache-Hit'), 'First request should not be a cache hit');
        $this->assertSame('0', $resp->getHeaderLine('X-Agavi-Container-Used'));
        $this->assertNull($req->getAttribute('_agavi_execution_container'));
        $this->assertStringContainsString('CACHE_HTML', (string)$resp->getBody());
    }
}
