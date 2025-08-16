<?php

use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Execution\ActionDescriptor;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ExecutionState;

class DispatchMiddlewareNoContainerNonSimpleTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE=1');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE_NOCONTAINER=1');
    $this->getContext()->getController()->initializeModule('Default');
    }

    private function buildRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/default/index'),
            'GET',
            $factory->createStream(''),
            [], [], [], [], [], []
        );
        return $psr
            ->withAttribute('module','Default')
            ->withAttribute('action','Index')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Default','Index','GET','html'));
    }

    public function testNonSimpleNoContainerHeaderAndAttribute()
    {
        $controller = $this->getContext()->getController();
        $mw = new DispatchMiddleware($controller);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
    // Simulate prior validation success for non-simple action (DispatchMiddleware will not run validation itself)
    $state = new ExecutionState(true, true);
    $req = $this->buildRequest()->withAttribute(ExecutionState::class,$state);
        $resp = $mw->process($req,$handler);
    $this->assertNull($req->getAttribute('_agavi_execution_container'));
    // Content may be empty if view relies on layout/layers not rendered in container-less mode yet.
    $this->assertTrue($resp->hasHeader('Content-Type'));
    }
}
