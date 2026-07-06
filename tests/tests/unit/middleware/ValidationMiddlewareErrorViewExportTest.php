<?php

use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ActionDescriptor;
use Quiote\Middleware\ValidationMiddleware;
use Sandbox\Modules\Snapshot\Actions\ExportOnErrorAction;

/**
 * Regression guard for ValidationMiddleware's post-handle*Error() re-fetch.
 * WebRequest is immutable: handle*Error() exports a value via setParameter(),
 * which only replaces its own local copy of the request unless it also
 * self-syncs via $this->getContext()->setRequest($request). Without the
 * re-fetch this middleware performs right after calling handle*Error() (and
 * before creating the error view), the rendered error view would see the
 * stale pre-export request instead (see ExportOnErrorAction/
 * ExportOnErrorActionErrorView).
 */
class ValidationMiddlewareErrorViewExportTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContext()->getController()->initializeModule('Snapshot');
    }

    public function testHandleErrorExportedParameterReachesTheRenderedErrorView(): void
    {
        $controller = $this->getContext()->getController();
        $actionDesc = new ActionDescriptor('Snapshot', 'ExportOnErrorAction', 'read', 'html', false);

        $request = (new ServerRequest('GET', '/snapshot/export-on-error'))
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'ExportOnErrorAction');

        $action = new ExportOnErrorAction();
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'Snapshot',
            'ExportOnErrorAction',
            $actionDesc->method,
            $actionDesc->outputType,
            $request,
            $controller->getGlobalResponse()
        ));
        $request = $request->withAttribute('quiote.preinstantiated_action', $action);

        $validation = new ValidationMiddleware($controller);
        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); }
        };

        $response = $validation->process($request, $finalHandler);

        $this->assertSame('ERROR_EXPORTED:exported-on-failure', (string) $response->getBody());
    }
}
