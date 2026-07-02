<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\ErrorHandlingMiddleware;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\ExecutionTimeMiddleware;
use Quiote\Middleware\TimingMiddleware;
use Quiote\Middleware\TraceMiddleware;
use Quiote\Middleware\ErrorHandlingMiddleware as EHM;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Comprehensive middleware pipeline coverage exercising build, reset, optional toggles,
 * error handling (HTML + JSON), status mapping, correlation id extraction, and sentinel safety.
 * NOTE: Caching branches in DispatchMiddleware remain skipped elsewhere; here we focus on pipeline
 * and error middleware coverage without re-enabling cache writes.
 */
class MiddlewarePipelineFullCoverageTest extends TestCase
{
    private function ctx(): Context
    {
        return Context::getInstance();
    }

    private function makeReq(array $headers = [], array $attrs = []): ServerRequestInterface
    {
        $r = new ServerRequest('GET', 'http://localhost/test', $headers);
        foreach ($attrs as $k => $v) {
            $r = $r->withAttribute($k, $v);
        }
        return $r;
    }

    public function setUp(): void
    {
        MiddlewareCatalog::initialize([]); // default: all enabled
        \Quiote\Config\Config::set('core.environment', 'development');
        \Quiote\Config\Config::set('core.developer_exceptions', false);
    }

    public function testPipelineBuildAndReuseAndReset()
    {
        $ctx = $this->ctx();
        $pipeline = new MiddlewarePipeline($ctx);
        $req = $this->makeReq();
        // First call builds
        try {
            $pipeline->handle($req);
        } catch (\Throwable) { /* ignore terminal if reached */
        }
        $firstOrder = $pipeline->debugStack();
        $this->assertNotEmpty($firstOrder);
        $this->assertEquals('__TERMINAL__', end($firstOrder));
        // Second call reuses (order identical, no append)
        $pipeline->handle($req);
        $secondOrder = $pipeline->debugStack();
        $this->assertSame($firstOrder, $secondOrder, 'Reused build should keep identical debug stack');
        // Reset triggers rebuild (order contents same logically but we can detect rebuild by clearing then repopulating)
        $pipeline->reset();
        $this->assertSame([], $pipeline->debugStack(), 'Debug stack cleared after reset before next handle');
        $pipeline->handle($req);
        $rebuilt = $pipeline->debugStack();
        $this->assertNotEmpty($rebuilt);
        $this->assertEquals('__TERMINAL__', end($rebuilt));
    }

    public function testOptionalMiddlewaresToggledIndependentlyAndCollectively()
    {
        $ctx = $this->ctx();
        // Disable Timing only
        MiddlewareCatalog::initialize([TimingMiddleware::class => false]);
        $p1 = new MiddlewarePipeline($ctx);
        $p1->handle($this->makeReq());
        $d1 = $p1->debugStack();
        $this->assertNotContains(TimingMiddleware::class, $d1);
        $this->assertContains(TraceMiddleware::class, $d1);
        // Disable Trace only
        MiddlewareCatalog::initialize([TraceMiddleware::class => false]);
        $p2 = new MiddlewarePipeline($ctx);
        $p2->handle($this->makeReq());
        $d2 = $p2->debugStack();
        $this->assertContains(TimingMiddleware::class, $d2);
        $this->assertNotContains(TraceMiddleware::class, $d2);
        // Disable ExecutionTime only
        MiddlewareCatalog::initialize([ExecutionTimeMiddleware::class => false]);
        $p3 = new MiddlewarePipeline($ctx);
        $p3->handle($this->makeReq());
        $d3 = $p3->debugStack();
        $this->assertNotContains(ExecutionTimeMiddleware::class, $d3);
        // Disable all three
        MiddlewareCatalog::initialize([
            TimingMiddleware::class => false,
            TraceMiddleware::class => false,
            ExecutionTimeMiddleware::class => false,
        ]);
        $p4 = new MiddlewarePipeline($ctx);
        $p4->handle($this->makeReq());
        $d4 = $p4->debugStack();
        $this->assertNotContains(TimingMiddleware::class, $d4);
        $this->assertNotContains(TraceMiddleware::class, $d4);
        $this->assertNotContains(ExecutionTimeMiddleware::class, $d4);
        $this->assertEquals('__TERMINAL__', end($d4));
    }

    public function testErrorHandlingStatusMappingsAndCorrelationHeaders()
    {
        $eh = new ErrorHandlingMiddleware();
        // 400 mapping; SafeRenderer is in effect (core.developer_exceptions is off by
        // default), so the message never reaches the client -- only the correlation id does.
        $r400 = $eh->process($this->makeReq(['Accept' => 'text/plain', 'Correlation-Id' => 'abc123']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new InvalidArgumentException('bad arg');
            }
        });
        $this->assertSame(400, $r400->getStatusCode());
        $body400 = (string)$r400->getBody();
        $this->assertStringNotContainsString('bad arg', $body400);
        $this->assertStringContainsString('abc123', $body400);
        // 422 mapping with fallback correlation header
        $r422 = $eh->process($this->makeReq(['X-Correlation-ID' => 'legacy-id']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new DomainException('bad domain');
            }
        });
        $this->assertSame(422, $r422->getStatusCode());
        // 500 generic
        $r500 = $eh->process($this->makeReq(), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('explode');
            }
        });
        $this->assertSame(500, $r500->getStatusCode());
    }

    public function testErrorHandlingJsonNegotiationViaAccept()
    {
        $eh = new ErrorHandlingMiddleware();
        $jsonResp = $eh->process($this->makeReq(['Accept' => 'application/json']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('jsonfail');
            }
        });
        $this->assertSame('application/json; charset=utf-8', $jsonResp->getHeaderLine('Content-Type'));
        // SafeRenderer never leaks the raw exception message into the JSON body.
        $this->assertStringNotContainsString('jsonfail', (string)$jsonResp->getBody());
        $payload = json_decode((string)$jsonResp->getBody(), true);
        $this->assertSame(['error' => 'Internal Server Error', 'status' => 500], $payload);
    }

    public function testDeveloperExceptionsOffMasksMessageRegardlessOfEnvironmentName()
    {
        // core.developer_exceptions -- not the environment name -- is the sole signal
        // (see docs/WHOOPS_ERROR_HANDLING_PLAN.md); assert this holds under an
        // environment named "production" as well as any other name.
        \Quiote\Config\Config::set('core.environment', 'production');
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        $eh = new ErrorHandlingMiddleware();
        $resp = $eh->process($this->makeReq(['Accept' => 'application/json']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('sensitive');
            }
        });
        $body = (string)$resp->getBody();
        $this->assertSame(500, $resp->getStatusCode());
        $this->assertStringNotContainsString('sensitive', $body, 'core.developer_exceptions off should never leak the raw message');
    }

    public function testCoreDebugOnDoesNotImplyDeveloperExceptions()
    {
        // core.debug carries unrelated, heavy behavior (historically: reparsing every
        // config file per request) and must never be conflated with developer_exceptions.
        \Quiote\Config\Config::set('core.debug', true);
        \Quiote\Config\Config::set('core.developer_exceptions', false);
        $eh = new ErrorHandlingMiddleware();
        $resp = $eh->process($this->makeReq(['Accept' => 'application/json']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('sensitive');
            }
        });
        $body = (string)$resp->getBody();
        \Quiote\Config\Config::set('core.debug', false);
        $this->assertStringNotContainsString('sensitive', $body, 'core.debug=true must not enable developer exception detail on its own');
    }

    public function testDeveloperExceptionsOnRevealsMessageRegardlessOfEnvironmentName()
    {
        // Same environment name as the test above ("production") but with the switch
        // flipped -- proves the environment name has zero bearing on the outcome.
        \Quiote\Config\Config::set('core.environment', 'production');
        \Quiote\Config\Config::set('core.developer_exceptions', true);
        $eh = new ErrorHandlingMiddleware();
        $resp = $eh->process($this->makeReq(['Accept' => 'application/json']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('sensitive');
            }
        });
        $body = (string)$resp->getBody();
        $this->assertSame(500, $resp->getStatusCode());
        $this->assertStringContainsString('sensitive', $body, 'core.developer_exceptions on should reveal the raw message via WhoopsRenderer');
    }

    public function testTerminalSentinelThrowsWhenNoResponse()
    {
        // Define a minimal pipeline subclass that omits all real middlewares so only sentinel exists.
        $ctx = $this->ctx();
        $testPipeline = new class($ctx) extends MiddlewarePipeline {
            // replicate constructor signature
            public function __construct($c)
            {
                parent::__construct($c);
            }
            // expose a public method to force build
            public function forceBuildOnlySentinel(): void
            {
                $ref = new \ReflectionClass(MiddlewarePipeline::class);
                $builtProp = $ref->getProperty('built');
                // $builtProp->setAccessible(true); // Deprecated, not needed in PHP 8.1+
                $handlerProp = $ref->getProperty('handler');
                // $handlerProp->setAccessible(true); // Deprecated, not needed in PHP 8.1+
                $debugProp = $ref->getProperty('debugStack');
                // $debugProp->setAccessible(true); // Deprecated, not needed in PHP 8.1+
                // Manually craft a handler that is just the sentinel middleware chain
                $sentinel = new class implements \Psr\Http\Server\MiddlewareInterface {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        throw new RuntimeException('Terminal pipeline reached without response');
                    }
                };
                $relay = new Relay\Relay([$sentinel]);
                $handlerProp->setValue($this, new readonly class($relay) implements RequestHandlerInterface {
                    public function __construct(private Relay\Relay $relay) {}
                    public function handle(ServerRequestInterface $r): ResponseInterface
                    {
                        return $this->relay->handle($r);
                    }
                });
                $debugProp->setValue($this, ['__TERMINAL__']);
                $builtProp->setValue($this, true);
            }
        };
        $testPipeline->forceBuildOnlySentinel();
        $this->assertEquals(['__TERMINAL__'], $testPipeline->debugStack());
        $this->expectException(RuntimeException::class);
        $testPipeline->handle($this->makeReq());
    }
}
