<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\TraceMiddleware;

final class TraceMiddlewareTest extends TestCase
{
    public function testEmitHeaderRecordsOwnClassNameInTrace(): void
    {
        $mw = new TraceMiddleware(true);
        $req = new ServerRequest('GET', '/');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertTrue($resp->hasHeader('X-Quiote-Trace'));
        $this->assertSame(TraceMiddleware::class, $resp->getHeaderLine('X-Quiote-Trace'));
    }

    public function testHeaderDisabledByDefaultLeavesResponseUntouched(): void
    {
        $mw = new TraceMiddleware();
        $req = new ServerRequest('GET', '/');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertFalse($resp->hasHeader('X-Quiote-Trace'));
    }

    public function testCustomHeaderNameIsHonored(): void
    {
        $mw = new TraceMiddleware(true, 'X-Debug-Trace');
        $req = new ServerRequest('GET', '/');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertTrue($resp->hasHeader('X-Debug-Trace'));
        $this->assertFalse($resp->hasHeader('X-Quiote-Trace'));
    }

    public function testTraceAccumulatesAcrossNestedMiddlewareOnSharedExecutionState(): void
    {
        $outer = new TraceMiddleware(true);
        $inner = new TraceMiddleware(false);
        $terminal = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        $innerHandler = new class($inner, $terminal) implements RequestHandlerInterface {
            public function __construct(private TraceMiddleware $inner, private RequestHandlerInterface $terminal) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->inner->process($request, $this->terminal);
            }
        };

        $resp = $outer->process(new ServerRequest('GET', '/'), $innerHandler);

        $this->assertSame(TraceMiddleware::class . ',' . TraceMiddleware::class, $resp->getHeaderLine('X-Quiote-Trace'));
    }

    /**
     * A pre-set ExecutionState attribute of the wrong type must be discarded
     * in favor of a fresh ExecutionState, and a pre-existing `metrics['trace']`
     * entry of the wrong type must be discarded rather than fed into implode().
     */
    public function testNonExecutionStateAttributeIsReplacedNotFatal(): void
    {
        $mw = new TraceMiddleware(true);
        $req = (new ServerRequest('GET', '/'))->withAttribute(ExecutionState::class, 'garbage');
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;
                return new Psr7Response(200);
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertSame(TraceMiddleware::class, $resp->getHeaderLine('X-Quiote-Trace'));
        $this->assertInstanceOf(ExecutionState::class, $handler->seen?->getAttribute(ExecutionState::class));
    }

    public function testMalformedExistingTraceMetricIsDiscardedNotFatal(): void
    {
        $exec = new ExecutionState();
        $exec->metrics = ['trace' => 'not-an-array'];
        $mw = new TraceMiddleware(true);
        $req = (new ServerRequest('GET', '/'))->withAttribute(ExecutionState::class, $exec);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertSame(TraceMiddleware::class, $resp->getHeaderLine('X-Quiote-Trace'));
    }
}
