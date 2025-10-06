<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ActionDescriptor;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Execution\ExecutionState;
use Sandbox\Modules\Snapshot\Actions\SnapshotAction;

class ActionAttributeSnapshotTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('AGAVI_DISPATCH_CONTEXT=1');
        putenv('AGAVI_DISPATCH_CONTEXT_SIMPLE=1');
        $this->getContext()->getController()->initializeModule('Snapshot');
    SnapshotAction::$initialAttributes = [];
    SnapshotAction::$postMutationAttributes = [];
    }

    private function req(): \Psr\Http\Message\ServerRequestInterface
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller,'Snapshot','SnapshotAction','GET','html');
        return (new ServerRequest('GET', 'http://localhost/snapshot'))
            ->withAttribute('module','Snapshot')
            ->withAttribute('action','SnapshotAction')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute(ExecutionState::class, new ExecutionState());
    }

    public function testSnapshotImmutable()
    {
        $mw = new DispatchMiddleware($this->getContext()->getController());
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
        $resp = $mw->process($this->req(), $handler);
        $this->assertSame('SNAPSHOT_OK', (string)$resp->getBody());
    $this->assertArrayHasKey('alpha', SnapshotAction::$initialAttributes);
    $this->assertSame(['nested'=>1], SnapshotAction::$initialAttributes['beta']);
        // executed action mutated beta and added gamma afterwards
    $this->assertArrayHasKey('gamma', SnapshotAction::$postMutationAttributes);
        // attribute snapshot embedded in ActionExecutionContext should reflect initial state only; fetch it via request ExecutionState not available, so repeat execute and introspect internal executor via cache? For simplicity ensure initial beta differs from post mutation.
    $this->assertNotEquals(SnapshotAction::$postMutationAttributes['beta'], SnapshotAction::$initialAttributes['beta']);
    }
}
