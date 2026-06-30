<?php

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Controller\AgaviController;
use Agavi\Execution\ActionDescriptor;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ValidationDecision;
use Agavi\Execution\SecurityDecision;
use Agavi\Execution\ActionExecutionContext;

/**
 * Tests the redirect snapshot functionality added to DispatchMiddleware.
 * Validates that redirects set in views are properly captured and bridged to PSR responses.
 */
class DispatchMiddlewareRedirectSnapshotTest extends TestCase
{
    private function bootstrapOutputType(AgaviController $controller): void
    {
        $ot = new \Agavi\Controller\AgaviOutputType();
        $ot->initialize($controller->getContext(), [], 'html', [], null, [], null, null);
        $ref = new ReflectionClass($controller);
        foreach(['outputTypes'=>'outputTypes','defaultOutputType'=>'defaultOutputType','configuredDefaultOutputType'=>'configuredDefaultOutputType'] as $prop=>$name){
            if($ref->hasProperty($prop)) { 
                $p=$ref->getProperty($prop); 
                if($prop==='outputTypes'){ 
                    $p->setValue($controller, ['html'=>$ot]); 
                } else { 
                    $p->setValue($controller, 'html'); 
                }
            }
        }
        $ctx = $controller->getContext();
        if($ctx instanceof PHPUnit\Framework\MockObject\MockObject) {
            $ctx->method('getController')->willReturn($controller);
        }
    }

    private function makeController(callable $actionFactory, array $cookies = [], ?array $redirectData = null): AgaviController
    {
        $ctx = $this->createStub(Agavi\AgaviContext::class);
        $webReq = new \Agavi\Request\AgaviWebRequest();
        $ctx->method('getRequest')->willReturn($webReq);
        $routing = new class { 
            public function getBasePath(){ return '/'; }
            public function getBaseHref(){ return 'http://example.com/'; }
        };
        $ctx->method('getRouting')->willReturn($routing);
        
        $globalResp = new class($cookies, $redirectData) extends \Agavi\Response\AgaviWebResponse {
            protected $redirect = null; 
            private $hasRedirect = false; 
            private $sent = false; 
            private $headers = [];
            
            public function __construct(private readonly array $cookiesData, ?array $redirectData = null){ 
                if ($redirectData) {
                    $this->redirect = $redirectData;
                    $this->hasRedirect = true;
                }
            }
            public function getCookies(): array { return $this->cookiesData; }
            public function setRedirect($url, $statusCode = 302) { 
                $this->redirect = ['location' => $url, 'code' => $statusCode]; 
                $this->hasRedirect = true; 
            }
            public function getRedirect() { return $this->redirect; }
            public function hasRedirect() { return $this->hasRedirect; }
            public function clearRedirect() { $this->redirect = null; $this->hasRedirect = false; }
            public function isSent() { return $this->sent; }
            public function send(?\Agavi\Controller\AgaviOutputType $outputType = null) { $this->sent = true; }
            public function setHttpHeader($name, $value, $replace = true) { 
                if($replace||!isset($this->headers[$name])){
                    $this->headers[$name]=[];
                } 
                $this->headers[$name][]=$value; 
            }
            public function getHttpHeader($name, $default = null) { return $this->headers[$name] ?? $default; }
            public function hasHttpHeader($name) { return isset($this->headers[$name]); }
            public function removeHttpHeader($name) { unset($this->headers[$name]); }
            public function clearHttpHeaders() { $this->headers = []; }
            public function clear() { $this->content = null; $this->clearHttpHeaders(); $this->clearRedirect(); }
        };
        
        $controller = new class($actionFactory, $globalResp) extends AgaviController {
            public function __construct(private $factory, private $gResp) {}
            public function getGlobalResponse() { return $this->gResp; }
            public function createActionInstance($moduleName, $actionName) { return ($this->factory)(); }
        };
        
        $ref = new ReflectionClass($controller);
        if($ref->hasProperty('context')) {
            $p = $ref->getProperty('context');
            $p->setValue($controller, $ctx);
        }
        return $controller;
    }

    private function makeActionDescriptor(bool $simple = true): ActionDescriptor
    {
        return new ActionDescriptor('TestModule', 'TestAction', 'read', 'html', $simple);
    }

    public function testBuildPsrResponseWithRedirect()
    {
        // Test that buildPsrResponse accepts redirect parameter
        $redirectData = ['location' => '/test-redirect', 'code' => 302];
        $actionFactory = function() {
            $action = $this->createStub(\Agavi\Action\AgaviAction::class);
            $action->method('isCacheable')->willReturn(false);
            return $action;
        };
        
        $controller = $this->makeController($actionFactory, [], $redirectData);
        $this->bootstrapOutputType($controller);
        
        $middleware = new DispatchMiddleware($controller);
        
        // Use reflection to call buildPsrResponse directly
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('buildPsrResponse');
        
        $response = $method->invoke($middleware, 'content', 'html', false, false, $redirectData);
        
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
        $this->assertStringContainsString('test-redirect', $response->getHeaderLine('Location'));
    }

    public function testBuildPsrResponseWithNullRedirect()
    {
        $actionFactory = function() {
            $action = $this->createStub(\Agavi\Action\AgaviAction::class);
            return $action;
        };
        
        $controller = $this->makeController($actionFactory);
        $this->bootstrapOutputType($controller);
        
        $middleware = new DispatchMiddleware($controller);
        
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('buildPsrResponse');
        
        $response = $method->invoke($middleware, 'content', 'html', false, false, null);
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Location'));
    }

    public function testBuildPsrResponseWithAbsoluteRedirectUrl()
    {
        $redirectData = ['location' => 'http://external.com/path', 'code' => 301];
        $actionFactory = function() {
            $action = $this->createStub(\Agavi\Action\AgaviAction::class);
            return $action;
        };
        
        $controller = $this->makeController($actionFactory, [], $redirectData);
        $this->bootstrapOutputType($controller);
        
        $middleware = new DispatchMiddleware($controller);
        
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('buildPsrResponse');
        
        $response = $method->invoke($middleware, 'content', 'html', false, false, $redirectData);
        
        $this->assertEquals(301, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
        $this->assertEquals('http://external.com/path', $response->getHeaderLine('Location'));
    }

    public function testBuildPsrResponseWithRelativeRedirectUrl()
    {
        $redirectData = ['location' => 'relative/path', 'code' => 303];
        $actionFactory = function() {
            $action = $this->createStub(\Agavi\Action\AgaviAction::class);
            return $action;
        };
        
        $controller = $this->makeController($actionFactory, [], $redirectData);
        $this->bootstrapOutputType($controller);
        
        $middleware = new DispatchMiddleware($controller);
        
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('buildPsrResponse');
        
        $response = $method->invoke($middleware, 'content', 'html', false, false, $redirectData);
        
        $this->assertEquals(303, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString('http://example.com/', $location);
        $this->assertStringContainsString('relative/path', $location);
    }
}
