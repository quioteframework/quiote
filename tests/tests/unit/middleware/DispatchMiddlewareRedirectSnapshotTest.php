<?php

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Controller\Controller;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ValidationDecision;
use Quiote\Execution\SecurityDecision;
use Quiote\Execution\ActionExecutionContext;

/**
 * Tests the redirect snapshot functionality added to DispatchMiddleware.
 * Validates that redirects set in views are properly captured and bridged to PSR responses.
 */
class DispatchMiddlewareRedirectSnapshotTest extends TestCase
{
    private function bootstrapOutputType(Controller $controller): void
    {
        $ot = new \Quiote\Controller\OutputType();
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

    /**
     * @param array<string, array<string, mixed>> $cookies
     * @param array{location: mixed, code: int|string}|null $redirectData
     */
    private function makeController(\Closure $actionFactory, array $cookies = [], ?array $redirectData = null): Controller
    {
        $ctx = $this->createStub(\Quiote\Context::class);
        $webReq = new \Quiote\Request\WebRequest();
        $ctx->method('getRequest')->willReturn($webReq);
        $routing = new class {
            public function getBasePath(): string { return '/'; }
            public function getBaseHref(): string { return 'http://example.com/'; }
        };
        $ctx->method('getRouting')->willReturn($routing);

        $globalResp = new class($cookies, $redirectData) extends \Quiote\Response\WebResponse {
            private bool $hasRedirect = false;
            private bool $sent = false;
            /** @var array<string, array<int, mixed>> */
            private array $headers = [];

            /**
             * @param array<string, array<string, mixed>> $cookiesData
             * @param array{location: mixed, code: int|string}|null $redirectData
             */
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
            public function isSent(): bool { return $this->sent; }
            public function send(?\Quiote\Controller\OutputType $outputType = null) { $this->sent = true; }
            public function setHttpHeader($name, $value, $replace = true) {
                if($replace||!isset($this->headers[$name])){
                    $this->headers[$name]=[];
                }
                $this->headers[$name][]=$value;
            }
            public function getHttpHeader($name, mixed $default = null) { return $this->headers[$name] ?? $default; }
            public function hasHttpHeader($name) { return isset($this->headers[$name]); }
            public function removeHttpHeader($name) { unset($this->headers[$name]); }
            public function clearHttpHeaders() { $this->headers = []; }
            public function clear() { $this->content = null; $this->clearHttpHeaders(); $this->clearRedirect(); }
        };

        $controller = new class($actionFactory, $globalResp) extends Controller {
            public function __construct(private readonly \Closure $factory, private readonly \Quiote\Response\WebResponse $gResp) {}
            public function getGlobalResponse(): \Quiote\Response\WebResponse { return $this->gResp; }
            public function createActionInstance($moduleName, $actionName): \Quiote\Action\Action { return ($this->factory)(); }
        };

        $ref = new ReflectionClass($controller);
        if($ref->hasProperty('context')) {
            $p = $ref->getProperty('context');
            $p->setValue($controller, $ctx);
        }
        return $controller;
    }

    public function testBuildPsrResponseWithRedirect(): void
    {
        // Test that buildPsrResponse accepts redirect parameter
        $redirectData = ['location' => '/test-redirect', 'code' => 302];
        $actionFactory = function() {
            $action = $this->createStub(\Quiote\Action\Action::class);
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

    public function testBuildPsrResponseWithNullRedirect(): void
    {
        $actionFactory = function() {
            $action = $this->createStub(\Quiote\Action\Action::class);
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

    public function testBuildPsrResponseWithAbsoluteRedirectUrl(): void
    {
        $redirectData = ['location' => 'http://external.com/path', 'code' => 301];
        $actionFactory = function() {
            $action = $this->createStub(\Quiote\Action\Action::class);
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

    public function testBuildPsrResponseWithRelativeRedirectUrl(): void
    {
        $redirectData = ['location' => 'relative/path', 'code' => 303];
        $actionFactory = function() {
            $action = $this->createStub(\Quiote\Action\Action::class);
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
