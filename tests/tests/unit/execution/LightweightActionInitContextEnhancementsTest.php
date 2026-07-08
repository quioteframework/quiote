<?php

use PHPUnit\Framework\TestCase;
use Quiote\Execution\LightweightActionInitContext;
use Quiote\Context;
use Quiote\Response\WebResponse;
use Nyholm\Psr7\ServerRequest;

/**
 * Additional tests for LightweightActionInitContext enhancements.
 * Tests validation manager support and PSR-7 request compatibility.
 */
class LightweightActionInitContextEnhancementsTest extends TestCase
{
    private function makeContext(): Context
    {
        $ctx = $this->createStub(Context::class);
        $request = new \Quiote\Request\WebRequest();
        $ctx->method('getRequest')->willReturn($request);
        return $ctx;
    }

    private function makeResponse(): WebResponse
    {
        return new class extends WebResponse {
            /** @var array{location: mixed, code: int|string}|null */
            protected $redirect = null;
            private bool $hasRedirect = false;
            /** @var array<string, array<int, mixed>> */
            private array $headers = [];

            public function getCookies(): array { return []; }
            public function setRedirect($url, $statusCode = 302): void {
                $this->redirect = ['location' => $url, 'code' => $statusCode];
                $this->hasRedirect = true;
            }
            public function getRedirect(): ?array { return $this->redirect; }
            public function hasRedirect(): bool { return $this->hasRedirect; }
            public function clearRedirect(): void { $this->redirect = null; $this->hasRedirect = false; }
            public function isSent(): bool { return false; }
            public function send(?\Quiote\Controller\OutputType $outputType = null): void {}
            public function setHttpHeader($name, $value, $replace = true): void {
                if($replace||!isset($this->headers[$name])){$this->headers[$name]=[];}
                $this->headers[$name][]=$value;
            }
            public function clear(): void { $this->clearHttpHeaders(); $this->clearRedirect(); }
        };
    }

    public function testValidationManagerGetterReturnsNull(): void
    {
        $context = $this->makeContext();
        $response = $this->makeResponse();
        $psrRequest = new ServerRequest('GET', '/test');
        
        $initContext = new LightweightActionInitContext(
            $context,
            'TestModule',
            'TestAction',
            'read',
            'html',
            $psrRequest,
            $response
        );
        
        $vm = $initContext->getValidationManager();
        $this->assertNull($vm);
    }

    public function testValidationManagerSetterAndGetter(): void
    {
        $context = $this->makeContext();
        $response = $this->makeResponse();
        $psrRequest = new ServerRequest('GET', '/test');
        
        $initContext = new LightweightActionInitContext(
            $context,
            'TestModule',
            'TestAction',
            'read',
            'html',
            $psrRequest,
            $response
        );
        
        $mockVm = $this->createStub(\Quiote\Validator\ValidationManager::class);
        $initContext->setValidationManager($mockVm);

        $retrievedVm = $initContext->getValidationManager();
        $this->assertSame($mockVm, $retrievedVm);
    }

    public function testPsrRequestCompatibility(): void
    {
        $context = $this->makeContext();
        $response = $this->makeResponse();
        $psrRequest = new ServerRequest('POST', '/api/test')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['key' => 'value']);
        
        $initContext = new LightweightActionInitContext(
            $context,
            'TestModule',
            'TestAction',
            'create',
            'json',
            $psrRequest,
            $response
        );
        
        $retrieved = $initContext->getRequestData();
        $this->assertInstanceOf(\Psr\Http\Message\ServerRequestInterface::class, $retrieved);
        $this->assertEquals('POST', $retrieved->getMethod());
        $this->assertEquals(['key' => 'value'], $retrieved->getParsedBody());
    }

    public function testUpdateMethodSupport(): void
    {
        $context = $this->makeContext();
        $response = $this->makeResponse();
        $psrRequest = new ServerRequest('PUT', '/resource/123');
        
        $initContext = new LightweightActionInitContext(
            $context,
            'Resource',
            'Update',
            'update',
            'html',
            $psrRequest,
            $response
        );
        
        $this->assertEquals('update', $initContext->getRequestMethod());
    }

    public function testAllSemanticMethods(): void
    {
        $methods = ['read', 'write', 'create', 'update', 'remove'];
        
        foreach ($methods as $method) {
            $context = $this->makeContext();
            $response = $this->makeResponse();
            $psrRequest = new ServerRequest('POST', '/test');
            
            $initContext = new LightweightActionInitContext(
                $context,
                'Test',
                'Action',
                $method,
                'html',
                $psrRequest,
                $response
            );
            
            $this->assertEquals($method, $initContext->getRequestMethod(), 
                "Method {$method} should be supported");
        }
    }
}
