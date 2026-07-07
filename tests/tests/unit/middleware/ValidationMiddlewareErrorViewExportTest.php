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
use Sandbox\Modules\Snapshot\Actions\LayerFallbackAction;
use Sandbox\Modules\Snapshot\Actions\FormRepopulationAction;

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

    /**
     * Regression guard: an HTML error view following the classic
     * loadLayout()/appendLayer() convention (execute() implicitly returns
     * null, content comes from a configured layer) used to produce a 400
     * response with an EMPTY body, because ValidationMiddleware's error-view
     * rendering never fell back to View::renderLayers() the way
     * ActionExecutor::renderView() does on the success path.
     */
    public function testHtmlErrorViewFallsBackToRenderLayersWhenExecuteReturnsNull(): void
    {
        $controller = $this->getContext()->getController();
        $actionDesc = new ActionDescriptor('Snapshot', 'LayerFallbackAction', 'read', 'html', false);

        $request = (new ServerRequest('GET', '/snapshot/layer-fallback'))
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'LayerFallbackAction');

        $action = new LayerFallbackAction();
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'Snapshot',
            'LayerFallbackAction',
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

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('LAYER_RENDERED', (string) $response->getBody());
    }

    /**
     * End-to-end regression guard for the "sticky form" bug: "name" has two
     * validators (length 3-7, not-numeric). "12345" passes the length check
     * but fails the not-numeric one, so WebRequest::pruneParametersToValidated()
     * scrubs it from the request even though "name" stays whitelisted. The
     * re-rendered error view must still show the submitted value via
     * ValidationManager::getRawParameterSnapshot() + FormPopulationEngine,
     * not lose it.
     */
    public function testFailedFormRepopulatesSubmittedValueThatFailedOneOfSeveralValidators(): void
    {
        $controller = $this->getContext()->getController();
        $actionDesc = new ActionDescriptor('Snapshot', 'FormRepopulationAction', 'write', 'html', false);

        $request = (new ServerRequest('POST', '/form-repopulation'))
            ->withParsedBody(['name' => '12345'])
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'FormRepopulationAction');

        $action = new FormRepopulationAction();
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'Snapshot',
            'FormRepopulationAction',
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
        $body = (string) $response->getBody();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('value="12345"', $body, 'Submitted value must be repopulated into the redisplayed form despite failing one of two validators');
    }
}
