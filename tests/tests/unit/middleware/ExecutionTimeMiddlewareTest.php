<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Http\PsrResponseAdapter;
use Quiote\Middleware\ExecutionTimeMiddleware;
use Quiote\Response\WebResponse;
use Quiote\Support\Clock\FrozenClock;

final class ExecutionTimeMiddlewareTest extends TestCase
{
    private function adapterWithContent(string $content): PsrResponseAdapter
    {
        $legacy = new WebResponse();
        $legacy->setContent($content);

        return new PsrResponseAdapter($legacy);
    }

    public function testAppendsAnHtmlCommentWithTheMeasuredDuration(): void
    {
        $mw = new ExecutionTimeMiddleware();
        $req = new ServerRequest('GET', '/');
        $adapter = $this->adapterWithContent('<html></html>');
        $handler = new class($adapter) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertInstanceOf(PsrResponseAdapter::class, $resp);
        $content = $resp->getLegacy()->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('<!-- exec_time=', $content);
    }

    public function testDisabledCommentLeavesContentUntouched(): void
    {
        $mw = new ExecutionTimeMiddleware(appendHtmlComment: false);
        $req = new ServerRequest('GET', '/');
        $adapter = $this->adapterWithContent('<html></html>');
        $handler = new class($adapter) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertSame('<html></html>', $resp instanceof PsrResponseAdapter ? $resp->getLegacy()->getContent() : null);
    }

    /**
     * A non-string body (streamed content) is left alone rather than corrupted
     * by appending a comment to something that isn't a string.
     */
    public function testNonStringContentIsNotAppendedTo(): void
    {
        $mw = new ExecutionTimeMiddleware();
        $req = new ServerRequest('GET', '/');
        $legacy = new WebResponse();
        // No content set at all: hasContent() is false, so appendContent() must not run.
        $adapter = new PsrResponseAdapter($legacy);
        $handler = new class($adapter) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertInstanceOf(PsrResponseAdapter::class, $resp);
        $this->assertFalse($resp->getLegacy()->hasContent());
    }

    /**
     * The appended duration is measured on the monotonic clock, so an NTP step
     * mid-request can't produce a negative or inflated value. Verified with a
     * FrozenClock the downstream handler itself advances.
     */
    public function testDurationIsMeasuredOnTheInjectedMonotonicClock(): void
    {
        $clock = new FrozenClock(1_000_000.0, 100.0);
        $mw = new ExecutionTimeMiddleware(clock: $clock);
        $req = new ServerRequest('GET', '/');
        $adapter = $this->adapterWithContent('<html></html>');
        $handler = new class($adapter, $clock) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response, private readonly FrozenClock $clock) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->clock->advance(0.5);
                return $this->response;
            }
        };

        $resp = $mw->process($req, $handler);

        $this->assertInstanceOf(PsrResponseAdapter::class, $resp);
        $content = $resp->getLegacy()->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('exec_time=500.00ms', $content);
    }
}
