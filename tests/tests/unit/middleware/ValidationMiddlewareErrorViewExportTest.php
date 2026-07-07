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
use Sandbox\Modules\Snapshot\Actions\SlotInErrorViewAction;
use Sandbox\Modules\Snapshot\Actions\SuccessFallbackAction;
use Sandbox\Modules\Snapshot\Actions\StatusOverrideFallbackAction;

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

    /**
     * These tests call ValidationMiddleware directly, bypassing
     * SecurityMiddleware, which is what normally guarantees
     * 'quiote.preinstantiated_action' is freshly (re)created for every real
     * request before ValidationMiddleware ever reads it. Without that
     * upstream reset, the action instance built here for the canonical
     * WebRequest would otherwise survive on Context past the end of the
     * test and leak into whatever test (in any file) runs next in this
     * process.
     */
    #[\Override]
    protected function tearDown(): void
    {
        try {
            $request = $this->getContext()->getRequest()
                ->withoutAttribute('quiote.preinstantiated_action')
                ->withoutAttribute(\Quiote\Middleware\SlotMiddleware::ATTR);
            $this->getContext()->setRequest($request);
        } catch (\Throwable) {
        }
        parent::tearDown();
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

    /**
     * Regression guard: SlotMiddleware normally runs AFTER ValidationMiddleware
     * (see MiddlewareAttributeOrderingTest), but the validation-failure path
     * renders its error view directly, without ever reaching SlotMiddleware.
     * An error view that calls renderSlot() used to throw "SlotStack missing
     * from request" as a result — see SlotInErrorViewAction.
     */
    public function testErrorViewCanRenderASlotEvenThoughSlotMiddlewareNeverRan(): void
    {
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
        $actionDesc = new ActionDescriptor('Snapshot', 'SlotInErrorViewAction', 'read', 'html', false);

        $request = (new ServerRequest('GET', '/snapshot/slot-in-error-view'))
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'SlotInErrorViewAction');

        $action = new SlotInErrorViewAction();
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'Snapshot',
            'SlotInErrorViewAction',
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
        $this->assertStringContainsString('SLOT:<div>CACHE_HTML</div>', (string) $response->getBody());
    }

    /**
     * Regression guard: handleReadError() returning the literal 'Success'
     * view name is a presentation choice only (e.g. an invalid ?lang=
     * falling back to the default locale's normal page instead of a
     * dedicated error page) — it must NOT double as an implicit status
     * override. A validation failure is always 400 regardless of what view
     * name string handle*Error() returns; nothing prevents an action from
     * returning an arbitrary string as the view name, so branching status
     * code on it would let that string smuggle an unintended 200 past a
     * client that only checks the status code.
     */
    public function testHandleErrorFallingBackToSuccessViewStillReturns400(): void
    {
        $controller = $this->getContext()->getController();
        $actionDesc = new ActionDescriptor('Snapshot', 'SuccessFallbackAction', 'read', 'html', false);

        $request = (new ServerRequest('GET', '/snapshot/success-fallback'))
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'SuccessFallbackAction');

        $action = new SuccessFallbackAction();
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'Snapshot',
            'SuccessFallbackAction',
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
        $this->assertTrue($response->hasHeader('X-Quiote-Validation'), 'A validation failure must always be marked as such, regardless of the fallback view name');
        $this->assertSame('FALLBACK_SUCCESS', (string) $response->getBody());
    }

    /**
     * The one supported way to make a validation-failure response carry a
     * status other than 400 is an explicit
     * getGlobalResponse()->setHttpStatusCode() call from handle*Error()/the
     * error view — mirroring the same convention
     * DispatchMiddleware::buildPsrResponse() already honors on the success
     * path — not an implicit inference from the view name string.
     */
    public function testHandleErrorCanExplicitlyOverrideStatusViaGlobalResponse(): void
    {
        $controller = $this->getContext()->getController();
        $actionDesc = new ActionDescriptor('Snapshot', 'StatusOverrideFallbackAction', 'read', 'html', false);

        $request = (new ServerRequest('GET', '/snapshot/status-override-fallback'))
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'Snapshot')
            ->withAttribute('action', 'StatusOverrideFallbackAction');

        $action = new StatusOverrideFallbackAction();
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'Snapshot',
            'StatusOverrideFallbackAction',
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

        $this->assertSame(409, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Quiote-Validation'), 'An explicit status override does not change that this was a validation failure');
        $this->assertSame('FALLBACK_TEAPOT', (string) $response->getBody());
    }
}
