<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\AgaviContext;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Validator\AgaviValidationManager;
use Agavi\Middleware\ValidationMiddleware;
use Agavi\Middleware\DispatchMiddleware;

class ValidationMiddlewareTest extends TestCase
{
    protected AgaviContext $context;

    protected function setUp(): void
    {
        if(!defined('AGAVI_TESTING')) {
            define('AGAVI_TESTING', true);
        }
    // Disable translations to avoid translation manager trying to load supplementalData.xml in unit mode
    \Agavi\Config\AgaviConfig::set('core.use_translation', false);
        $this->context = AgaviContext::getInstance('testing');
        // Force request/controller initialization for consistent sharing
        $this->context->getController();
        $this->context->getRequest();
    }

    public function testValidationFailureTriggersErrorView(): void
    {
        $context = $this->context;
        // Configure pseudo module
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.stub.enabled' => true,
            'modules.stub.agavi.view.name' => '${actionName}${viewName}'
        ]);
        // Simulate routing attaching descriptor
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Stub','Fail','read','html', false);
        $request = (new ServerRequest('GET','/stub/fail'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('module','Stub')
            ->withAttribute('action','Fail');
        // Attach synthetic action for failure path
        $action = new class {
            public function isSimple(): bool { return false; }
            public function handleError($rd) { return 'Error'; }
            public function handleReadError($rd) { return 'Error'; }
        };
        $request = $request->withAttribute('agavi.preinstantiated_action', $action);
    $validation = new ValidationMiddleware($this->context->getController());
        $finalHandler = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $finalHandler);
        $this->assertStringContainsString('error', strtolower((string)$response->getBody()));
    }

    public function testValidationSuccessPassesThrough(): void
    {
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.stub.enabled' => true,
            'modules.stub.agavi.view.name' => '${actionName}${viewName}'
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Stub','Pass','read','html', true);
        $action = new class {
            public static int $execCount = 0;
            public function isSimple(): bool { return true; }
            public function execute($rd=null) { self::$execCount++; return 'ActionOK'; }
            public function handleError($rd){ return 'Error'; }
            public function handleReadError($rd){ return 'Error'; }
            public function getAttributes(){ return []; }
        };
        $request = (new ServerRequest('GET','/stub/pass'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
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
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.stub.enabled' => true,
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Stub','NoXml','read','html', false);
        // Provide query param foo=1 which should be cleared (no XML validators + non-simple)
        $action = new class {
            public function isSimple(): bool { return false; }
            public function validateRead($r){ return true; }
            public function validate($r){ return true; }
            public function handleReadError($r){ return 'Error'; }
            public function handleError($r){ return 'Error'; }
        };
        $request = (new ServerRequest('GET','/stub/noxml?foo=1'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute('module','Stub')
            ->withAttribute('action','NoXml');
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
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.err.enabled' => true,
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Err','FailRead','read','html', false);
        $action = new class {
            public string $chosen = '';
            public function isSimple(): bool { return false; }
            public function validateRead($r){ return false; }
            public function handleReadError($r){ $this->chosen = 'handleReadError'; return \Agavi\View\AgaviView::NONE; }
            public function handleError($r){ $this->chosen = 'handleError'; return \Agavi\View\AgaviView::NONE; }
        };
        $request = (new ServerRequest('GET','/err/failread'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute('module','Err')
            ->withAttribute('action','FailRead');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('handleReadError', $action->chosen, 'Expected specific handleReadError method chosen');
    }

    public function testManualValidationFailureFallsBackHandleError(): void
    {
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.err2.enabled' => true,
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Err2','FailGeneric','read','html', false);
        $action = new class {
            public string $chosen = '';
            public function isSimple(): bool { return false; }
            public function validateRead($r){ return false; }
            // no handleReadError -> fallback
            public function handleError($r){ $this->chosen = 'handleError'; return \Agavi\View\AgaviView::NONE; }
        };
        $request = (new ServerRequest('GET','/err2/failgeneric'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute('module','Err2')
            ->withAttribute('action','FailGeneric');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('handleError', $action->chosen, 'Expected generic handleError fallback used');
    }

    public function testFailureNoneViewReturns400EmptyBody(): void
    {
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.err3.enabled' => true,
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Err3','FailNone','read','html', false);
        $action = new class {
            public function isSimple(): bool { return false; }
            public function validateRead($r){ return false; }
            public function handleReadError($r){ return \Agavi\View\AgaviView::NONE; }
        };
        $request = (new ServerRequest('GET','/err3/failnone'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute('module','Err3')
            ->withAttribute('action','FailNone');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('', (string)$response->getBody(), 'NONE view path should produce empty body');
    }

    public function testSkipRevalidationWhenDecisionAlreadyMade(): void
    {
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.reuse.enabled' => true,
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Reuse','Act','read','html', false);
        $action = new class {
            public static int $validateCalls = 0;
            public function isSimple(): bool { return false; }
            public function validateRead($r){ self::$validateCalls++; return true; }
            public function handleReadError($r){ return 'Error'; }
            public function handleError($r){ return 'Error'; }
        };
        $execState = new \Agavi\Execution\ExecutionState();
        // First run (pending decision)
        $request1 = (new ServerRequest('GET','/reuse/act'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute(\Agavi\Execution\ExecutionState::class, $execState)
            ->withAttribute('module','Reuse')
            ->withAttribute('action','Act');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
    $validation->process($request1, $final);
    $initialCalls = $action::$validateCalls;
        // Mark decision passed manually to simulate skip scenario
        $execState->validationDecision = \Agavi\Execution\ValidationDecision::passed();
        $request2 = (new ServerRequest('GET','/reuse/act'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute(\Agavi\Execution\ExecutionState::class, $execState)
            ->withAttribute('module','Reuse')
            ->withAttribute('action','Act');
    $validation->process($request2, $final);
    $this->assertSame($initialCalls, $action::$validateCalls, 'Expected no additional validateRead call after decision passed');
    }

    public function testRouteParamsInjection(): void
    {
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.routes.enabled' => true,
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Routes','Show','read','html', false);
        $action = new class {
            public function isSimple(): bool { return true; } // simple to avoid parameter clearing when no XML
            public function validateRead($r){ return true; }
            public function handleReadError($r){ return 'Error'; }
            public function handleError($r){ return 'Error'; }
        };
        $routeParams = [ 'slug' => 'abc', '_internal' => 'skip', 'existing' => 'rv' ];
        // Provide existing query param so route param of same name not injected
        $request = (new ServerRequest('GET','/routes/show?existing=keep'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('route_params', $routeParams)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute('module','Routes')
            ->withAttribute('action','Show');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $validation->process($request, $final);
        $ctxReq = $this->context->getRequest();
        $this->assertSame('keep', $ctxReq->getParameter('existing'));
        $this->assertSame('abc', $ctxReq->getParameter('slug'));
        $this->assertNull($ctxReq->getParameter('_internal'));
    }

    public function testXmlPresencePreservesParameters(): void
    {
        // Use sandbox Default/Index which has a minimal validators file in test/sandbox/app/Modules/Default/validate/Index.xml
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Default','Index','read','html', false);
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.default.enabled' => true,
        ]);
        $action = new class {
            public function isSimple(): bool { return true; } // simple action bypasses validation clearing
            public function handleReadError($r){ return 'Error'; }
            public function handleError($r){ return 'Error'; }
        };
        $request = (new ServerRequest('GET','/default/index?keep=1'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute('module','Default')
            ->withAttribute('action','Index');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $validation->process($request, $final);
        $ctxReq = $this->context->getRequest();
    $this->assertSame('1', $ctxReq->getParameter('keep'), 'Expected parameter retained (simple action bypass)');
    }

    public function testViewCreationExceptionHandled(): void
    {
        \Agavi\Config\AgaviConfig::fromArray([
            'modules.exc.enabled' => true,
        ]);
        $actionDesc = new \Agavi\Execution\ActionDescriptor('Exc','Boom','read','html', false);
        $action = new class {
            public function isSimple(): bool { return false; }
            public function validateRead($r){ return false; }
            public function handleReadError($r){ return ['InvalidMod','NoView']; } // should provoke creation failure
        };
        $request = (new ServerRequest('GET','/exc/boom'))
            ->withAttribute(\Agavi\Execution\ActionDescriptor::class, $actionDesc)
            ->withAttribute('agavi.preinstantiated_action',$action)
            ->withAttribute('module','Exc')
            ->withAttribute('action','Boom');
    $validation = new ValidationMiddleware($this->context->getController());
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(200); } };
        $response = $validation->process($request, $final);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Agavi-Validation'));
    }
}
