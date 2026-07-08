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

/**
 * Regression guard for ActionExecutor::doExecute() re-fetching the request
 * from Context after the action's execute*() method runs. WebRequest is
 * immutable: a value exported via setParameter() only replaces the action's
 * own local copy of the request, so it must self-sync via
 * $this->getContext()->setRequest($request), AND ActionExecutor must re-fetch
 * before rendering the view -- both halves are required, or the exported
 * value never reaches the view (see ExportParamAction/ExportParamActionSuccessView).
 */
class ActionExportReachesViewTest extends UnitTestCase
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
    }

    public function testParameterExportedByActionExecuteReachesTheRenderedView(): void
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller, 'Snapshot', 'ExportParamAction', 'GET', 'html');

        $request = (new ServerRequest('GET', 'http://localhost/snapshot/export'))
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'ExportParamAction')
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute(ExecutionState::class, new ExecutionState());

        $dispatch = new DispatchMiddleware($controller);
        $final = new class(new Psr17Factory) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $f) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200); }
        };
        $dispatchAsHandler = new class($dispatch, $final) implements RequestHandlerInterface {
            public function __construct(private DispatchMiddleware $mw, private RequestHandlerInterface $final) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->mw->process($r, $this->final); }
        };

        $validation = new ValidationMiddleware($controller);
        $resp = $validation->process($request, $dispatchAsHandler);

        $this->assertSame(
            'EXPORTED:from-action',
            (string) $resp->getBody(),
            'Value exported via setParameter()+self-sync inside execute() must reach the rendered view'
        );
    }
}
