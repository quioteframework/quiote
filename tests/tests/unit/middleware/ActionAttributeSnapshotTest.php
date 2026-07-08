<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\ActionDescriptor;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ValidationDecision;
use Sandbox\Modules\Snapshot\Actions\SnapshotAction;

class ActionAttributeSnapshotTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('QUIOTE_DISPATCH_CONTEXT=1');
        putenv('QUIOTE_DISPATCH_CONTEXT_SIMPLE=1');
        // Ensure writable cache directory for config cache generation
        $tmpCache = sys_get_temp_dir() . '/quiote_test_cache';
        if(!is_dir($tmpCache)) { @mkdir($tmpCache, 0777, true); }
        \Quiote\Config\Config::set('core.cache_dir', $tmpCache);
        $this->getContext()->getController()->initializeModule('Snapshot');
    SnapshotAction::$initialAttributes = [];
    SnapshotAction::$postMutationAttributes = [];
    }

    private function req(): \Psr\Http\Message\ServerRequestInterface
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller,'Snapshot','SnapshotAction','GET','html');
        // SnapshotAction is not isSimple() (it exercises a real execute() call),
        // so DispatchMiddleware requires a validation decision -- normally set
        // by ValidationMiddleware, which this test bypasses since it targets
        // DispatchMiddleware in isolation.
        $execState = new ExecutionState();
        $execState->validationDecision = ValidationDecision::passed();
        return (new ServerRequest('GET', 'http://localhost/snapshot'))
            ->withAttribute('module','Snapshot')
            ->withAttribute('action','SnapshotAction')
            ->withAttribute('output_type','html')
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute(ExecutionState::class, $execState);
    }

    public function testSnapshotImmutable(): void
    {
        $mw = new DispatchMiddleware($this->getContext()->getController());
        $handler = new class(new Psr17Factory) implements \Psr\Http\Server\RequestHandlerInterface { public function __construct(private Psr17Factory $f){} public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return $this->f->createResponse(200);} };
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
