<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionExecutionSession;

class DispatchMiddlewareSessionContainerPathTest extends AgaviUnitTestCase
{
    protected function setUp(): void { parent::setUp(); $this->markTestSkipped('Container path removed; session container path test deprecated.'); }

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

    public function testSessionAttachedOnContainerPath() { $this->fail('Skipped'); }
}
