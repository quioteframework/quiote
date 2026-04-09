<?php

use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Middleware\ValidationMiddleware;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\LightweightActionInitContext;
use Agavi\Action\AgaviAction;
use Agavi\Request\AgaviWebRequest;

/**
 * Tests that ValidationMiddleware correctly handles the 'update' method
 * which was added to the list of allowed semantic tokens.
 */
class ValidationMiddlewareUpdateMethodTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset context request to fresh instance to avoid state pollution from prior tests
        // (withAttribute/clone gives shallow copy sharing the parameterHolder object)
        $fresh = new AgaviWebRequest();
        $fresh->initialize($this->getContext());
        $this->getContext()->setRequest($fresh);
    }

    private function makeInitializedAction(AgaviAction $action, string $method, string $module, string $actionName): AgaviAction
    {
        $ctx  = $this->getContext();
        $resp = $ctx->getController()->getGlobalResponse();
        $req  = $ctx->getRequest();
        $initCtx = new LightweightActionInitContext($ctx, $module, $actionName, $method, 'html', $req, $resp);
        $action->initialize($initCtx);
        return $action;
    }

    public function testUpdateMethodIsRecognizedAsValidSemanticToken()
    {
        $actionDesc = new ActionDescriptor('Cache', 'Cache', 'update', 'html', false);

        $action = new class extends AgaviAction {
            public bool $validateUpdateCalled = false;
            public function isSimple(): bool { return false; }
            public function validateUpdate(AgaviWebRequest $rd): bool {
                $this->validateUpdateCalled = true;
                return true;
            }
            public function validate(AgaviWebRequest $rd): bool { return true; }
            public function executeUpdate(AgaviWebRequest $rd): string { return 'Success'; }
            public function handleError(AgaviWebRequest $rd): string { return 'Error'; }
        };
        $this->makeInitializedAction($action, 'update', 'Cache', 'Cache');

        $request = $this->getContext()->getRequest()
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action', $action);

        $validation = new ValidationMiddleware($this->getContext()->getController());
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new \Nyholm\Psr7\Response(200));

        $response = $validation->process($request, $handler);

        $this->assertTrue($action->validateUpdateCalled, 'validateUpdate should have been called for method=update');
    }

    public function testUpdateMethodPassesThroughOnSuccess()
    {
        $actionDesc = new ActionDescriptor('Cache', 'Cache', 'update', 'html', false);

        $action = new class extends AgaviAction {
            public function isSimple(): bool { return false; }
            public function validateUpdate(AgaviWebRequest $rd): bool { return true; }
            public function validate(AgaviWebRequest $rd): bool { return true; }
            public function executeUpdate(AgaviWebRequest $rd): string { return 'Success'; }
            public function handleError(AgaviWebRequest $rd): string { return 'Error'; }
        };
        $this->makeInitializedAction($action, 'update', 'Cache', 'Cache');

        $request = $this->getContext()->getRequest()
            ->withAttribute(ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action', $action);

        $validation = new ValidationMiddleware($this->getContext()->getController());
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new \Nyholm\Psr7\Response(200));

        $response = $validation->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAllSemanticMethodsPassWhenValidationSucceeds()
    {
        $methods = ['read', 'write', 'create', 'update', 'remove'];

        foreach ($methods as $method) {
            $actionDesc = new ActionDescriptor('Cache', 'Cache', $method, 'html', false);

            $action = new class extends AgaviAction {
                public function isSimple(): bool { return false; }
                public function validate(AgaviWebRequest $rd): bool { return true; }
                public function execute(AgaviWebRequest $rd): string { return 'Success'; }
                public function handleError(AgaviWebRequest $rd): string { return 'Error'; }
            };
            $this->makeInitializedAction($action, $method, 'Cache', 'Cache');

            $request = $this->getContext()->getRequest()
                ->withAttribute(ActionDescriptor::class, $actionDesc)
                ->withAttribute('agavi.preinstantiated_action', $action);

            $validation = new ValidationMiddleware($this->getContext()->getController());
            $handler = $this->createStub(RequestHandlerInterface::class);
            $handler->method('handle')->willReturn(new \Nyholm\Psr7\Response(200));

            $response = $validation->process($request, $handler);
            $this->assertEquals(200, $response->getStatusCode(), "Method {$method} should pass when validate() returns true");
        }
    }
}
