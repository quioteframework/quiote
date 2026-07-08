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
 * End-to-end guard: isSimple() means "skip execute*() entirely and render
 * getDefaultViewName() directly" (Agavi heritage, commit f166330f4) -- not
 * "run execute*() but with restricted/cleared parameter access". A simple
 * action's execute() must never run at all, for any reason, so there is no
 * business-logic code path left that could read a raw, attacker-controlled
 * route/query/body parameter in the first place.
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

    public function testSimpleActionNeverRunsExecute(): void
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
            public function __construct(private Psr17Factory $f) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->f->createResponse(200); }
        };
        // Chain validation -> dispatch, mirroring the real pipeline order.
        $dispatchAsHandler = new class($dispatch, $final) implements RequestHandlerInterface {
            public function __construct(private DispatchMiddleware $mw, private RequestHandlerInterface $final) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->mw->process($r, $this->final); }
        };

        $validation = new ValidationMiddleware($controller);
        $resp = $validation->process($request, $dispatchAsHandler);

        // Snapshot immediately: $seenParams is a plain static array captured once,
        // asserted against below, so nothing later in this method can affect it.
        $seen = ParamSnapshotAction::$seenParams;

        // The view still renders (via getDefaultViewName(), not execute()'s return value).
        $this->assertSame('PARAM_OK', (string) $resp->getBody());
        // execute() must never have run at all -- not "ran with params cleared".
        $this->assertSame([], $seen, 'execute() must never run for a simple action');
    }
}
