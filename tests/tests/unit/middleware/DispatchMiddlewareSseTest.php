<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Cache\CacheManager;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Execution\ActionDescriptor;
use Quiote\Execution\ExecutionState;
use Quiote\Execution\ValidationDecision;
use Quiote\Http\Sse\SseEvent;
use Quiote\Http\Sse\SseStream;
use Quiote\Http\Sse\SseStreamingAction;
use Quiote\Request\WebRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

/**
 * A fake action that both a simple- and non-simple-branch test can
 * instantiate directly (via the `quiote.preinstantiated_action` request
 * attribute DispatchMiddleware already honours) without needing a real
 * sandbox module/view of its own.
 */
final class FakeSseAction extends \Quiote\Action\Action implements SseStreamingAction
{
    #[\Override]
    public function streamEvents(WebRequest $request): iterable
    {
        yield SseEvent::of('hello', event: 'greeting', id: '1');
        yield 'raw';
    }
}

class DispatchMiddlewareSseTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CacheManager::reset();
        putenv('QUIOTE_DISPATCH_CONTEXT=1');
        putenv('QUIOTE_DISPATCH_CONTEXT_SIMPLE=1');
    }

    private function buildHandler(): \Psr\Http\Server\RequestHandlerInterface
    {
        return new class(new Psr17Factory()) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private Psr17Factory $f)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface
            {
                return $this->f->createResponse(200);
            }
        };
    }

    private function assertStreamsSse(\Psr\Http\Message\ResponseInterface $resp): void
    {
        $this->assertSame('text/event-stream', $resp->getHeaderLine('Content-Type'));
        $this->assertSame('no-cache', $resp->getHeaderLine('Cache-Control'));
        $this->assertSame('keep-alive', $resp->getHeaderLine('Connection'));
        $this->assertSame('no', $resp->getHeaderLine('X-Accel-Buffering'));
        $body = $resp->getBody();
        $this->assertInstanceOf(SseStream::class, $body);
        $this->assertSame("event: greeting\nid: 1\ndata: hello\n\ndata: raw\n\n", (string)$body);
    }

    public function testNonSimpleActionStreamsInsteadOfRenderingAView(): void
    {
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
        $descriptor = ActionDescriptor::fromController($controller, 'Cache', 'Cache', 'GET', 'html');
        $this->assertFalse($descriptor->isSimple, 'Precondition: Cache:Cache is expected to be a non-simple action');

        $state = new ExecutionState();
        $state->validationDecision = ValidationDecision::passed();

        $request = (new ServerRequest('GET', 'http://localhost/cache'))
            ->withAttribute('module', 'Cache')
            ->withAttribute('action', 'Cache')
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute(ExecutionState::class, $state)
            ->withAttribute('quiote.preinstantiated_action', new FakeSseAction());

        $mw = new DispatchMiddleware($controller);
        $resp = $mw->process($request, $this->buildHandler());

        $this->assertStreamsSse($resp);
    }

    public function testSimpleActionStreamsInsteadOfRenderingAView(): void
    {
        $controller = $this->getContext()->getController();
        $descriptor = ActionDescriptor::fromController($controller, 'ControllerTests', 'SimpleAction', 'GET', 'html');
        $this->assertTrue($descriptor->isSimple, 'Precondition: ControllerTests:SimpleAction is expected to be a simple action');

        $request = (new ServerRequest('GET', 'http://localhost/controller-tests'))
            ->withAttribute('module', 'ControllerTests')
            ->withAttribute('action', 'SimpleAction')
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $descriptor)
            ->withAttribute(ExecutionState::class, new ExecutionState())
            ->withAttribute('quiote.preinstantiated_action', new FakeSseAction());

        $mw = new DispatchMiddleware($controller);
        $resp = $mw->process($request, $this->buildHandler());

        $this->assertStreamsSse($resp);
    }
}
