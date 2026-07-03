<?php

use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Sandbox\Modules\Snapshot\Actions\ParamSnapshotAction;

/**
 * End-to-end guard for perf change A3: ValidationMiddleware now skips its
 * pipeline-request overlay for simple actions. This proves the two parameter
 * sources a simple action depends on still reach it through the full
 * ValidationMiddleware -> DispatchMiddleware -> ActionExecutor chain:
 *   - a route param ({id}), promoted by ValidationMiddleware, and
 *   - a query param (?q=), applied by ActionExecutor::buildRequestDataFromPsr.
 */
class SimpleActionParamPipelineTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('QUIOTE_DISPATCH_CONTEXT=1');
        putenv('QUIOTE_DISPATCH_CONTEXT_SIMPLE=1');
        $tmpCache = sys_get_temp_dir() . '/quiote_test_cache';
        if (!is_dir($tmpCache)) { @mkdir($tmpCache, 0777, true); }
        \Quiote\Config\Config::set('core.cache_dir', $tmpCache);
        $this->getContext()->getController()->initializeModule('Snapshot');
        ParamSnapshotAction::$seenParams = [];
    }

    public function testSimpleActionReceivesRouteAndQueryParams(): void
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller, 'Snapshot', 'ParamSnapshotAction', 'GET', 'html');

        $request = (new ServerRequest('GET', 'http://localhost/snapshot/param'))
            ->withQueryParams(['q' => 'hello'])
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'ParamSnapshotAction')
            ->withAttribute('output_type', 'html')
            ->withAttribute('route_params', ['id' => '42'])
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute(ExecutionState::class, new ExecutionState());

        $dispatch = new DispatchMiddleware($controller);
        $final = new class(new Psr17Factory) implements RequestHandlerInterface {
            public function __construct(private $f) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200); }
        };
        // Chain validation -> dispatch, mirroring the real pipeline order.
        $dispatchAsHandler = new class($dispatch, $final) implements RequestHandlerInterface {
            public function __construct(private DispatchMiddleware $mw, private RequestHandlerInterface $final) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->mw->process($r, $this->final); }
        };

        $validation = new ValidationMiddleware($controller);
        $resp = $validation->process($request, $dispatchAsHandler);

        $this->assertSame('PARAM_OK', (string) $resp->getBody());
        $this->assertSame('42', ParamSnapshotAction::$seenParams['id'] ?? null, 'route param {id} must reach a simple action (ValidationMiddleware promotion)');
        $this->assertSame('hello', ParamSnapshotAction::$seenParams['q'] ?? null, 'query param ?q= must reach a simple action (ActionExecutor buildRequestDataFromPsr)');
    }
}
