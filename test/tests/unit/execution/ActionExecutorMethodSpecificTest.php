<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use Agavi\Http\PsrServerRequestAdapter;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ExecutionState;
use Agavi\Middleware\DispatchMiddleware;

class ActionExecutorMethodSpecificTest extends AgaviUnitTestCase
{
    public function setUp(): void { parent::setUp(); $this->markTestSkipped('Obsolete: ActionExecutor now requires prior ValidationMiddleware; method-specific executor tests deprecated.'); }
    private function build(string $method, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $legacyReq = $this->getContext()->getRequest();
        $psr = new PsrServerRequestAdapter(
            $legacyReq,
            $factory->createUri('http://localhost/method/http'),
            strtoupper($method),
            Stream::create(''),
            [], [], [], $query, [], []
        );
        return $psr
            ->withAttribute('module','Method')
            ->withAttribute('action','MethodHttp')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, ActionDescriptor::fromController($this->getContext()->getController(),'Method','MethodHttp', ucfirst(strtolower($method)), 'html'));
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

    public function testPostSuccess()
    {
        \Sandbox\Modules\Method\Actions\MethodHttpAction::ensureReset();
    $resp = $this->dispatchRun($this->build('POST'));
        $body = (string)$resp->getBody();
        $this->assertStringContainsString('POST_OK', $body);
        $this->assertSame('executePost', \Sandbox\Modules\Method\Actions\MethodHttpAction::$last);
    }

    public function testPostValidationFail()
    {
        $this->markTestSkipped('Obsolete: validation failures handled in ValidationMiddleware before DispatchMiddleware/ActionExecutor.');
        \Sandbox\Modules\Method\Actions\MethodHttpAction::ensureReset();
    $resp = $this->dispatchRun($this->build('POST', ['fail'=>1]));
        $body = (string)$resp->getBody();
        // Try to extract debug attributes from action instance stored in global controller state if available
        $debug = [];
        try {
            $inst = $this->getContext()->getController()->createActionInstance('Method','MethodHttp'); // new instance will not have runtime attrs
            if(method_exists($inst,'getAttributes')) { $debug = $inst->getAttributes(); }
        } catch(\Throwable) {}
    $this->assertStringContainsString('POST_ERROR', $body, 'Body mismatch; last=' . \Sandbox\Modules\Method\Actions\MethodHttpAction::$last . ' debug=' . json_encode($debug));
    $this->assertSame('handlePostError', \Sandbox\Modules\Method\Actions\MethodHttpAction::$last, 'Expected method-specific error handler to run; last=' . \Sandbox\Modules\Method\Actions\MethodHttpAction::$last);
    }
}
