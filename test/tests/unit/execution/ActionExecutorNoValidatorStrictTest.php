<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ExecutionState;
use Agavi\Middleware\DispatchMiddleware;

class ActionExecutorNoValidatorStrictTest extends AgaviUnitTestCase
{
    private function build(string $method, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/method/novalhttp'),
            strtoupper($method),
            Stream::create(''),
            [], [], [], $query, [], []
        );
        return $psr
            ->withAttribute('module','Method')
            ->withAttribute('action','NoValHttp')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Method','NoValHttp', ucfirst(strtolower($method)), 'html'));
    }

    private function dispatchRun(\Psr\Http\Message\ServerRequestInterface $req): \Psr\Http\Message\ResponseInterface
    {
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE=1');
        putenv('AGAVI_DISPATCH_CONTEXT_NONSIMPLE_NOCONTAINER=1');
        $controller = $this->getContext()->getController();
        $mw = new DispatchMiddleware($controller);
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
        $state = new ExecutionState();
        return $mw->process($req->withAttribute(ExecutionState::class, $state), $handler);
    }

    public function testPostParamStrippedUnderStrictMode()
    {
        \Sandbox\Modules\Method\Actions\NoValHttpAction::ensureReset();
        $resp = $this->dispatchRun($this->build('POST', ['fail'=>1]));
        $body = (string)$resp->getBody();
        $this->assertStringContainsString('NOVAL_POST_OK', $body, 'Expected success view because fail param should be stripped before validatePost runs');
        $this->assertSame('executePost', \Sandbox\Modules\Method\Actions\NoValHttpAction::$last, 'Expected executePost; last=' . \Sandbox\Modules\Method\Actions\NoValHttpAction::$last);
    }
}
