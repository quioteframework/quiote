<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Context;
use Quiote\Request\RequestDataHolder;
use Quiote\Validator\ValidationManager;
use Quiote\Middleware\ValidationMiddleware;
use Quiote\Middleware\DispatchMiddleware;

class ValidationMiddlewareTest extends TestCase
{
    protected Context $context;

    protected function setUp(): void
    {
        if(!defined('QUIOTE_TESTING')) {
            define('QUIOTE_TESTING', true);
        }
    // Disable translations to avoid translation manager trying to load supplementalData.xml in unit mode
    \Quiote\Config\Config::set('core.use_translation', false);
        $this->context = Context::getInstance('testing');
        // Force request/controller initialization for consistent sharing
        $this->context->getController();
        $this->context->getRequest();
        // Strict validation: seed whitelist with potential parameter names used across tests
        $req = $this->context->getRequest();
        $req = $req->enforceValidatedParameters(['foo','existing','slug','_internal','keep']);
        $this->context->setRequest($req);
    }

    /**
     * Gives a test-fixture Action a real ActionInitContext, mirroring what
     * ValidationMiddleware itself does for pre-instantiated actions in
     * production. Without this, $action->getContext() is null and
     * ValidationService::xmlOnlyValidate() has nothing to build a
     * ValidationManager from.
     */
    private function initializeAction(\Quiote\Action\Action $action, string $module, string $actionName, string $method, \Psr\Http\Message\ServerRequestInterface $request): void
    {
        $controller = $this->context->getController();
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            $module,
            $actionName,
            $method,
            'html',
            $request,
            $controller->getGlobalResponse()
        ));
    }

    public function testValidationFailureTriggersErrorView(): void
    {
        $context = $this->context;
        // Configure pseudo module
        \Quiote\Config\Config::fromArray([
            'modules.stub.enabled' => true,
            'modules.stub.quiote.view.name' => '${actionName}${viewName}'
        ]);
        // Simulate routing attaching descriptor
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Stub','Fail','read','html', false);
        $request = (new ServerRequest('GET','/stub/fail'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module','Stub')
            ->withAttribute('action','Fail');
        // Attach synthetic action for failure path
        $action = new class extends \Quiote\Action\Action {
            public function isSimple(): bool { return false; }
            public function validateRead(\Quiote\Request\WebRequest $rd): bool { return false; }
            public function handleError($rd) { return 'Error'; }
            public function handleReadError(\Quiote\Request\WebRequest $rd): string { return 'Error'; }
        };
        $this->initializeAction($action, 'Stub', 'Fail', 'read', $request);
        $request = $request->withAttribute('quiote.preinstantiated_action', $action);
    $validation = new ValidationMiddleware($this->context->getController());
        $finalHandler = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $finalHandler);
        $this->assertStringContainsString('error', strtolower((string)$response->getBody()));
    }

    public function testValidationSuccessPassesThrough(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.stub.enabled' => true,
            'modules.stub.quiote.view.name' => '${actionName}${viewName}'
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Stub','Pass','read','html', true);
        $action = new class {
            public static int $execCount = 0;
            public function isSimple(): bool { return true; }
            public function execute(?\Quiote\Request\WebRequest $rd=null): string { self::$execCount++; return 'ActionOK'; }
            public function handleError(\Quiote\Request\WebRequest $rd): string { return 'Error'; }
            public function handleReadError(\Quiote\Request\WebRequest $rd): string { return 'Error'; }
            /** @return array<never, never> */
            public function getAttributes(): array { return []; }
        };
        $request = (new ServerRequest('GET','/stub/pass'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('quiote.preinstantiated_action',$action)
            ->withAttribute('module','Stub')
            ->withAttribute('action','Pass');
    $validation = new ValidationMiddleware($this->context->getController());
        $finalHandler = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $response = $validation->process($request, $finalHandler);
        // Since simple action returns immediately only validation middleware runs; ensure no validation error content produced.
    // Simple action validation bypass now allows pipeline to continue to final handler (204 expected).
    $this->assertSame(204, $response->getStatusCode());
    }

    public function testNoXmlNonSimpleClearsParameters(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.stub.enabled' => true,
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Stub','NoXml','read','html', false);
        // Provide query param foo=1 which should be cleared (no XML validators + non-simple)
        $action = new class extends \Quiote\Action\Action {
            public function isSimple(): bool { return false; }
            public function validateRead(\Quiote\Request\WebRequest $r): bool { return true; }
            public function validate($r){ return true; }
            public function handleReadError(\Quiote\Request\WebRequest $r): string { return 'Error'; }
            public function handleError($r){ return 'Error'; }
        };
        $request = (new ServerRequest('GET','/stub/noxml?foo=1'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module','Stub')
            ->withAttribute('action','NoXml');
        $this->initializeAction($action, 'Stub', 'NoXml', 'read', $request);
        $request = $request->withAttribute('quiote.preinstantiated_action',$action);
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $response = $validation->process($request, $final);
    $this->assertContains($response->getStatusCode(), [204,400], 'Expected pass-through or failure status when no XML present');
        // After processing, parameters should be cleared on canonical request instance
        $ctxReq = $this->context->getRequest();
        $this->assertNull($ctxReq->getParameter('foo'), 'Expected foo parameter cleared when no XML validators present');
    }

    public function testManualValidationFailureUsesHandleReadError(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.err.enabled' => true,
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Err','FailRead','read','html', false);
        $action = new class extends \Quiote\Action\Action {
            public string $chosen = '';
            public function isSimple(): bool { return false; }
            public function validateRead(\Quiote\Request\WebRequest $r): bool { return false; }
            public function handleReadError(\Quiote\Request\WebRequest $r): null { $this->chosen = 'handleReadError'; return \Quiote\View\View::NONE; }
            public function handleError($r){ $this->chosen = 'handleError'; return \Quiote\View\View::NONE; }
        };
        $request = (new ServerRequest('GET','/err/failread'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module','Err')
            ->withAttribute('action','FailRead');
        $this->initializeAction($action, 'Err', 'FailRead', 'read', $request);
        $request = $request->withAttribute('quiote.preinstantiated_action',$action);
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('handleReadError', $action->chosen, 'Expected specific handleReadError method chosen');
    }

    public function testManualValidationFailureFallsBackHandleError(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.err2.enabled' => true,
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Err2','FailGeneric','read','html', false);
        $action = new class extends \Quiote\Action\Action {
            public string $chosen = '';
            public function isSimple(): bool { return false; }
            public function validateRead(\Quiote\Request\WebRequest $r): bool { return false; }
            // no handleReadError -> fallback
            public function handleError($r){ $this->chosen = 'handleError'; return \Quiote\View\View::NONE; }
        };
        $request = (new ServerRequest('GET','/err2/failgeneric'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module','Err2')
            ->withAttribute('action','FailGeneric');
        $this->initializeAction($action, 'Err2', 'FailGeneric', 'read', $request);
        $request = $request->withAttribute('quiote.preinstantiated_action',$action);
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('handleError', $action->chosen, 'Expected generic handleError fallback used');
    }

    public function testFailureNoneViewReturns400EmptyBody(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.err3.enabled' => true,
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Err3','FailNone','read','html', false);
        $action = new class extends \Quiote\Action\Action {
            public function isSimple(): bool { return false; }
            public function validateRead(\Quiote\Request\WebRequest $r): bool { return false; }
            public function handleReadError(\Quiote\Request\WebRequest $r): null { return \Quiote\View\View::NONE; }
        };
        $request = (new ServerRequest('GET','/err3/failnone'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module','Err3')
            ->withAttribute('action','FailNone');
        $this->initializeAction($action, 'Err3', 'FailNone', 'read', $request);
        $request = $request->withAttribute('quiote.preinstantiated_action',$action);
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('', (string)$response->getBody(), 'NONE view path should produce empty body');
    }

    public function testSkipRevalidationWhenDecisionAlreadyMade(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.reuse.enabled' => true,
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Reuse','Act','read','html', false);
        $action = new class extends \Quiote\Action\Action {
            public static int $validateCalls = 0;
            public function isSimple(): bool { return false; }
            public function validateRead(\Quiote\Request\WebRequest $r): bool { self::$validateCalls++; return true; }
            public function handleReadError(\Quiote\Request\WebRequest $r): string { return 'Error'; }
            public function handleError($r){ return 'Error'; }
        };
        $execState = new \Quiote\Execution\ExecutionState();
        // First run (pending decision)
        $request1 = (new ServerRequest('GET','/reuse/act'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute(\Quiote\Execution\ExecutionState::class, $execState)
            ->withAttribute('module','Reuse')
            ->withAttribute('action','Act');
        $this->initializeAction($action, 'Reuse', 'Act', 'read', $request1);
        $request1 = $request1->withAttribute('quiote.preinstantiated_action',$action);
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
    $validation->process($request1, $final);
    $initialCalls = $action::$validateCalls;
        // Mark decision passed manually to simulate skip scenario
        $execState->validationDecision = \Quiote\Execution\ValidationDecision::passed();
        $request2 = (new ServerRequest('GET','/reuse/act'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('quiote.preinstantiated_action',$action)
            ->withAttribute(\Quiote\Execution\ExecutionState::class, $execState)
            ->withAttribute('module','Reuse')
            ->withAttribute('action','Act');
    $validation->process($request2, $final);
    $this->assertSame($initialCalls, $action::$validateCalls, 'Expected no additional validateRead call after decision passed');
    }

    public function testRouteParamsInjection(): void
    {
        // isSimple() means the action needs NO parameters at all -- a route
        // path segment's VALUE is just as attacker-controlled as a query/body
        // parameter (e.g. slug could be "' OR 1=1;--"), so simple actions must
        // get zero parameters from any source, including route params.
        \Quiote\Config\Config::fromArray([
            'modules.routes.enabled' => true,
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Routes','Show','read','html', false);
        $action = new class {
            public function isSimple(): bool { return true; }
            public function validateRead(\Quiote\Request\WebRequest $r): bool { return true; }
            public function handleReadError(\Quiote\Request\WebRequest $r): string { return 'Error'; }
            public function handleError(\Quiote\Request\WebRequest $r): string { return 'Error'; }
        };
        $routeParams = [ 'slug' => 'abc', '_internal' => 'skip', 'existing' => 'rv' ];
        $seeded = $this->context->getRequest()->withQueryParams(['existing' => 'keep']);
        $this->context->setRequest($seeded);
        $request = (new \Quiote\Request\WebRequest('GET','/routes/show'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('route_params', $routeParams)
            ->withAttribute('quiote.preinstantiated_action',$action)
            ->withAttribute('module','Routes')
            ->withAttribute('action','Show');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $validation->process($request, $final);
        $ctxReq = $this->context->getRequest();
        $this->assertNull($ctxReq->getParameter('existing', null), 'Simple actions must not receive pre-existing query params');
        $this->assertNull($ctxReq->getParameter('slug', null), 'Simple actions must not receive route params');
        $this->assertNull($ctxReq->getParameter('_internal', null));
    }

    public function testXmlPresenceDoesNotExemptSimpleActionsFromClearing(): void
    {
        // Use sandbox Default/Index which has a minimal validators file in test/sandbox/app/Modules/Default/validate/Index.xml
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Default','Index','read','html', false);
        \Quiote\Config\Config::fromArray([
            'modules.default.enabled' => true,
        ]);
        $action = new class {
            public function isSimple(): bool { return true; }
            public function handleReadError(\Quiote\Request\WebRequest $r): string { return 'Error'; }
            public function handleError(\Quiote\Request\WebRequest $r): string { return 'Error'; }
        };
        // Even a query param with a matching validators.xml entry elsewhere in
        // the module must not survive for a genuinely simple action -- isSimple()
        // means "needs no parameters", full stop, regardless of what XML config
        // exists for the module/action pair.
        $seeded = $this->context->getRequest()->withQueryParams(['keep' => '1']);
        $this->context->setRequest($seeded);
        $request = (new ServerRequest('GET','/default/index'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('quiote.preinstantiated_action',$action)
            ->withAttribute('module','Default')
            ->withAttribute('action','Index');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $validation->process($request, $final);
        $ctxReq = $this->context->getRequest();
    $this->assertNull($ctxReq->getParameter('keep', null), 'Expected parameter cleared even for a simple action');
    }

    public function testManuallyRegisteredValidatorViaValidatorBuilderWhitelistsAndPreservesSubmittedValue(): void
    {
        // Regression test for the v1.0.0 release bug: an action that registers
        // validators purely via ValidatorBuilder::on($this->getInitContext()->getValidationManager(), ...)
        // in registerWriteValidators() (no validators.xml file) used to have those
        // validators silently skipped by xmlOnlyValidate(), so the submitted "name"
        // parameter stayed unwhitelisted and getParameter('name') in executeWrite()
        // threw UnvalidatedParameterAccessException even though validation passed.
        \Quiote\Config\Config::fromArray([
            'modules.inputtest.enabled' => true,
        ]);
        $controller = $this->context->getController();
        $actionDesc = new \Quiote\Execution\ActionDescriptor('InputTest', 'Submit', 'write', 'html', false);
        $action = new class extends \Quiote\Action\Action {
            public ?string $capturedName = null;
            public function getDefaultViewName() { return 'Input'; }
            public function executeWrite(\Quiote\Request\WebRequest $rd): string
            {
                $this->capturedName = $rd->getParameter('name');
                return 'Success';
            }
            public function handleError(\Quiote\Request\WebRequest $rd) { return 'Input'; }
            public function registerWriteValidators(): void
            {
                $initContext = $this->getInitContext();
                $context = $this->getContext();
                if ($initContext === null || $context === null) {
                    throw new \RuntimeException('Action must be initialize()d before registerWriteValidators() runs.');
                }
                $v = \Quiote\Validator\Compiler\Runtime\ValidatorBuilder::on(
                    $initContext->getValidationManager(),
                    $context,
                );
                $v->string('name', required: true)
                    ->minLength(3)
                    ->maxLength(7)
                    ->error('Name must be between 3 and 7 characters long.');
            }
        };
        $request = (new ServerRequest('POST', '/input-test'))
            ->withParsedBody(['name' => 'Bob'])
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'InputTest')
            ->withAttribute('action', 'Submit');
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'InputTest',
            'Submit',
            'write',
            'html',
            $request,
            $controller->getGlobalResponse()
        ));
        $request = $request->withAttribute('quiote.preinstantiated_action', $action);

        $validation = new ValidationMiddleware($controller);
        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                /** @var \Quiote\Request\WebRequest $r */
                $action = $r->getAttribute('quiote.preinstantiated_action');
                $action->executeWrite($r);
                return new Psr7Response(200);
            }
        };

        $validation->process($request, $finalHandler);

        $this->assertSame('Bob', $action->capturedName, 'Expected the submitted "name" value to be whitelisted and retrievable in executeWrite()');
    }

    public function testManuallyRegisteredValidatorViaValidatorBuilderFailsForInvalidValue(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.inputtest2.enabled' => true,
        ]);
        $controller = $this->context->getController();
        $actionDesc = new \Quiote\Execution\ActionDescriptor('InputTest2', 'Submit', 'write', 'html', false);
        $action = new class extends \Quiote\Action\Action {
            public function getDefaultViewName() { return 'Input'; }
            public function executeWrite(\Quiote\Request\WebRequest $rd): string { return 'Success'; }
            public function handleError(\Quiote\Request\WebRequest $rd) { return 'Input'; }
            public function registerWriteValidators(): void
            {
                $initContext = $this->getInitContext();
                $context = $this->getContext();
                if ($initContext === null || $context === null) {
                    throw new \RuntimeException('Action must be initialize()d before registerWriteValidators() runs.');
                }
                $v = \Quiote\Validator\Compiler\Runtime\ValidatorBuilder::on(
                    $initContext->getValidationManager(),
                    $context,
                );
                $v->string('name', required: true)
                    ->minLength(3)
                    ->maxLength(7)
                    ->error('Name must be between 3 and 7 characters long.');
            }
        };
        // "AB" is too short (< 3 chars) -> validator must fail and route to handleError,
        // never reaching executeWrite().
        $request = (new ServerRequest('POST', '/input-test'))
            ->withParsedBody(['name' => 'AB'])
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module', 'InputTest2')
            ->withAttribute('action', 'Submit');
        $action->initialize(new \Quiote\Execution\LightweightActionInitContext(
            $controller->getContext(),
            'InputTest2',
            'Submit',
            'write',
            'html',
            $request,
            $controller->getGlobalResponse()
        ));
        $request = $request->withAttribute('quiote.preinstantiated_action', $action);

        $validation = new ValidationMiddleware($controller);
        $finalHandler = new class implements RequestHandlerInterface {
            public bool $reachedDispatch = false;
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                $this->reachedDispatch = true;
                return new Psr7Response(200);
            }
        };

        $response = $validation->process($request, $finalHandler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($finalHandler->reachedDispatch, 'Expected validation failure to short-circuit before dispatch');
    }

    public function testViewCreationExceptionHandled(): void
    {
        \Quiote\Config\Config::fromArray([
            'modules.exc.enabled' => true,
        ]);
        $actionDesc = new \Quiote\Execution\ActionDescriptor('Exc','Boom','read','html', false);
        $action = new class extends \Quiote\Action\Action {
            public function isSimple(): bool { return false; }
            public function validateRead(\Quiote\Request\WebRequest $r): bool { return false; }
            /** @return array<int, string> */
            public function handleReadError(\Quiote\Request\WebRequest $r): array { return ['InvalidMod','NoView']; } // should provoke creation failure
        };
        $request = (new ServerRequest('GET','/exc/boom'))
            ->withAttribute(\Quiote\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module','Exc')
            ->withAttribute('action','Boom');
        $this->initializeAction($action, 'Exc', 'Boom', 'read', $request);
        $request = $request->withAttribute('quiote.preinstantiated_action',$action);
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Quiote-Validation'));
    }
}
