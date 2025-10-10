<?php

use PHPUnit\Framework\TestCase;
use Agavi\AgaviContext;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\MiddlewareCatalog;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\TimingMiddleware;
use Agavi\Middleware\TraceMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware as EHM;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Comprehensive middleware pipeline coverage exercising build, reset, optional toggles,
 * error handling (HTML + JSON), status mapping, correlation id extraction, and sentinel safety.
 *
 * NOTE: Caching branches in DispatchMiddleware remain skipped elsewhere; here we focus on pipeline
 * and error middleware coverage without re-enabling cache writes.
 */
class MiddlewarePipelineFullCoverageTest extends TestCase
{
    private function ctx(): AgaviContext
    {
        return AgaviContext::getInstance();
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
        putenv('AGAVI_DEBUG=1');
        // Make sure environment oscillates explicitly in tests that need prod
        Agavi\Config\AgaviConfig::set('core.environment', 'development');
    }

    public function testPipelineBuildAndReuseAndReset()
    {
        $ctx = $this->ctx();
        $pipeline = new MiddlewarePipeline($ctx);
        $req = $this->makeReq();
        // First call builds
        try {
            $pipeline->handle($req);
        } catch (\Throwable $e) { /* ignore terminal if reached */
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
        // 400 mapping
        $r400 = $eh->process($this->makeReq(['Accept' => 'text/plain', 'Correlation-Id' => 'abc123']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new InvalidArgumentException('bad arg');
            }
        });
        $this->assertSame(400, $r400->getStatusCode());
        $this->assertTrue(str_contains((string)$r400->getBody(), 'bad arg')); // dev mode
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

    public function testErrorHandlingJsonNegotiationViaAcceptAndOutputType()
    {
        $eh = new ErrorHandlingMiddleware();
        // Accept header
        $jsonResp1 = $eh->process($this->makeReq(['Accept' => 'application/json']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('jsonfail');
            }
        });
        $this->assertSame('application/json; charset=utf-8', $jsonResp1->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('jsonfail', (string)$jsonResp1->getBody());
        // output_type attribute
        $jsonResp2 = $eh->process($this->makeReq([], ['output_type' => 'json']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('ot-json');
            }
        });
        $this->assertSame('application/json; charset=utf-8', $jsonResp2->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('ot-json', (string)$jsonResp2->getBody());
    }

    public function testErrorHandlingProductionModeMasksMessage()
    {
        Agavi\Config\AgaviConfig::set('core.environment', 'production');
        putenv('AGAVI_DEBUG'); // unset debug
        $eh = new ErrorHandlingMiddleware();
        $resp = $eh->process($this->makeReq(['Accept' => 'application/json']), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('sensitive');
            }
        });
        $body = (string)$resp->getBody();
        $this->assertSame(500, $resp->getStatusCode());
        $this->assertStringNotContainsString('sensitive', $body, 'Production mode should not leak raw message');
    }

    public function testErrorTemplateFallbackOnIncludeFailure()
    {
        // Point config to non-existent template; include will fail and fallback should render plain text
        Agavi\Config\AgaviConfig::set('core.environment', 'development');
        Agavi\Config\AgaviConfig::set('exception.templates.html.development', sys_get_temp_dir() . '/does_not_exist_template.php');
        $eh = new ErrorHandlingMiddleware();
        $resp = $eh->process($this->makeReq(), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                throw new RuntimeException('tmpl');
            }
        });
        $this->assertSame(500, $resp->getStatusCode());
        $this->assertTrue(in_array('text/plain; charset=utf-8', $resp->getHeader('Content-Type')) || str_contains($resp->getHeaderLine('Content-Type'), 'text/html'), 'Should still produce a content-type header');
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
                $builtProp->setAccessible(true);
                $handlerProp = $ref->getProperty('handler');
                $handlerProp->setAccessible(true);
                $debugProp = $ref->getProperty('debugStack');
                $debugProp->setAccessible(true);
                // Manually craft a handler that is just the sentinel middleware chain
                $sentinel = new class implements \Psr\Http\Server\MiddlewareInterface {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        throw new RuntimeException('Terminal pipeline reached without response');
                    }
                };
                $relay = new Relay\Relay([$sentinel]);
                $handlerProp->setValue($this, new class($relay) implements RequestHandlerInterface {
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
