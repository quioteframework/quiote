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
        $this->context = AgaviContext::getInstance('testing');
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
        $validation = new ValidationMiddleware();
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
        $validation = new ValidationMiddleware();
        $finalHandler = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Psr7Response(204); } };
        $response = $validation->process($request, $finalHandler);
        // Since simple action returns immediately only validation middleware runs; ensure no validation error content produced.
        $this->assertSame(204, $response->getStatusCode());
    }
}
