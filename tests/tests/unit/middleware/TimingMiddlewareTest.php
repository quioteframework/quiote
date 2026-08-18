<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Execution\ExecutionState;
use Quiote\Middleware\TimingMiddleware;
use Quiote\Support\Clock\FrozenClock;

/**
 * json_encode() is typed to return string|false; TimingMiddleware previously fed its result
 * straight into withHeader() without checking for false. The array being encoded here only
 * ever contains a rounded float, so json_encode() cannot realistically fail — but PHPStan
 * cannot know that statically, and the guard is the correct defensive fix rather than a cast.
 */
final class TimingMiddlewareTest extends TestCase
{
    public function testEmitHeaderAddsTimingHeaderWithEncodedTotalMs(): void
    {
        $mw = new TimingMiddleware(true);
        $req = new ServerRequest('GET', '/');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        $resp = $mw->process($req, $handler);
        $this->assertTrue($resp->hasHeader('X-Quiote-Timing'));
        $decoded = json_decode($resp->getHeaderLine('X-Quiote-Timing'), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('total_ms', $decoded);
        $this->assertIsFloat($decoded['total_ms']);
    }

    public function testHeaderDisabledByDefaultLeavesResponseUntouched(): void
    {
        $mw = new TimingMiddleware();
        $req = new ServerRequest('GET', '/');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        $resp = $mw->process($req, $handler);
        $this->assertFalse($resp->hasHeader('X-Quiote-Timing'));
    }

    /**
     * A pre-set ExecutionState attribute of the wrong type (e.g. seeded by
     * misbehaving app code) must be discarded in favor of a fresh
     * ExecutionState, not fatal when TimingMiddleware writes ->metrics onto it.
     */
    public function testNonExecutionStateAttributeIsReplacedNotFatal(): void
    {
        $mw = new TimingMiddleware(true);
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

        $this->assertTrue($resp->hasHeader('X-Quiote-Timing'));
        $this->assertInstanceOf(ExecutionState::class, $handler->seen?->getAttribute(ExecutionState::class));
    }

    /**
     * total_ms is measured on the monotonic clock, so an NTP step mid-request
     * can't produce a negative or inflated duration. Verified with a
     * FrozenClock the downstream handler itself advances -- total_ms comes
     * out exactly the advanced amount rather than "some non-negative number".
     */
    public function testTotalMsIsMeasuredOnTheInjectedMonotonicClock(): void
    {
        $clock = new FrozenClock(1_000_000.0, 100.0);
        $mw = new TimingMiddleware(emitHeader: true, clock: $clock);
        $req = new ServerRequest('GET', '/');
        $handler = new class($clock) implements RequestHandlerInterface {
            public function __construct(private readonly FrozenClock $clock) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->clock->advance(0.25);
                return new Psr7Response(200);
            }
        };

        $resp = $mw->process($req, $handler);

        $decoded = json_decode($resp->getHeaderLine('X-Quiote-Timing'), true);
        $this->assertIsArray($decoded);
        $this->assertSame(250.0, $decoded['total_ms']);
    }
}
