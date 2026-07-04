<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Telemetry\MiddlewareSpanDecorator;
use Quiote\Telemetry\TelemetryBootstrap;

/**
 * Unit tests for MiddlewareSpanDecorator in isolation: the wrapper every
 * pipeline middleware gets when `telemetry.spans.middleware` is on.
 */
class MiddlewareSpanDecoratorTest extends TestCase
{
    #[Before]
    public function setUpTelemetry(): void
    {
        TelemetryBootstrap::reset();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        TelemetryBootstrap::reset();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
    }

    private function enable(): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', 'always_on', true);
        TelemetryBootstrap::configureFromConfig();
    }

    private function passthrough(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
    }

    private function terminal(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
    }

    public function testWrappedMiddlewareStillRunsNormally(): void
    {
        $this->enable();
        $decorator = new MiddlewareSpanDecorator($this->passthrough(), 'App\\Middleware\\Example');

        $response = $decorator->process(new ServerRequest('GET', '/x'), $this->terminal());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSpanIsNamedByTheGivenLabel(): void
    {
        $this->enable();
        $decorator = new MiddlewareSpanDecorator($this->passthrough(), 'App\\Middleware\\Example');

        $decorator->process(new ServerRequest('GET', '/x'), $this->terminal());

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame('App\\Middleware\\Example', $spans[0]->getName());
    }

    public function testDisabledTelemetryProducesNoSpanAndStillWorks(): void
    {
        $decorator = new MiddlewareSpanDecorator($this->passthrough(), 'App\\Middleware\\Example');

        $response = $decorator->process(new ServerRequest('GET', '/x'), $this->terminal());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testExceptionFromInnerMiddlewareIsRecordedAndRethrown(): void
    {
        $this->enable();
        $throwing = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('middleware blew up');
            }
        };
        $decorator = new MiddlewareSpanDecorator($throwing, 'App\\Middleware\\Throwing');

        $caught = null;
        try {
            $decorator->process(new ServerRequest('GET', '/x'), $this->terminal());
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'the exception must propagate to the outer pipeline, not be swallowed');
        $this->assertSame('middleware blew up', $caught->getMessage());

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $this->assertCount(1, $spans, 'the span must still be exported even though the middleware failed');
        $this->assertSame('Error', $spans[0]->getStatus()->getCode());
    }

    public function testChainedDecoratorsNestCorrectly(): void
    {
        // Two decorators stacked, mirroring how the real pipeline wraps
        // several middlewares in sequence: the outer's span must be the
        // parent of the inner's.
        $this->enable();
        $inner = new MiddlewareSpanDecorator($this->passthrough(), 'Inner');
        $outer = new class($inner) implements MiddlewareInterface {
            public function __construct(private MiddlewareInterface $next) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->next->process($request, $handler);
            }
        };
        $decorated = new MiddlewareSpanDecorator($outer, 'Outer');

        $decorated->process(new ServerRequest('GET', '/x'), $this->terminal());

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $byName = [];
        foreach ($spans as $s) {
            $byName[$s->getName()] = $s;
        }
        $this->assertSame(
            $byName['Outer']->getContext()->getSpanId(),
            $byName['Inner']->getParentSpanId(),
        );
    }
}
