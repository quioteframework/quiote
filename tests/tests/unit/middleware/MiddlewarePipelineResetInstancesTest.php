<?php

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Context;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Symfony\Contracts\Service\ResetInterface;

require_once __DIR__ . '/OutputBufferNormalizer.php';

/**
 * A middleware that keeps something from the request it handled -- the shape
 * the worker-lifetime stack makes dangerous, and the one ResetInterface exists
 * to make safe.
 */
class ResettableProbeMiddleware implements MiddlewareInterface, ResetInterface
{
    public ?string $sawPath = null;
    public int $resets = 0;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->sawPath = $request->getUri()->getPath();

        return new Response(204);
    }

    public function reset(): void
    {
        $this->resets++;
        $this->sawPath = null;
    }
}

class ThrowingResetMiddleware implements MiddlewareInterface, ResetInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }

    public function reset(): void
    {
        throw new \RuntimeException('reset failed');
    }
}

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class MiddlewarePipelineResetInstancesTest extends TestCase
{
    private int $initialObLevel;

    protected function setUp(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareCatalog::initialize([]);
        $this->initialObLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        MiddlewareCatalog::reset();
        while (ob_get_level() > $this->initialObLevel) {
            ob_end_clean();
        }
    }

    private function handle(MiddlewarePipeline $pipeline, string $path): void
    {
        $normalizer = new OutputBufferNormalizer();
        $pipeline->handle(new ServerRequest('GET', $path));
        $normalizer->normalize();
    }

    public function testResetInstancesClearsWhatAMiddlewareKeptFromTheRequest(): void
    {
        $probe = new ResettableProbeMiddleware();
        MiddlewareCatalog::register(ResettableProbeMiddleware::class, static fn(): MiddlewareInterface => $probe);

        $pipeline = new MiddlewarePipeline(Context::getInstance());
        $this->handle($pipeline, '/first-request');
        $this->assertSame('/first-request', $probe->sawPath, 'precondition: the middleware ran and kept state');

        $pipeline->resetInstances();

        $this->assertSame(1, $probe->resets);
        $this->assertNull($probe->sawPath);
    }

    public function testResetInstancesKeepsTheBuiltStack(): void
    {
        $probe = new ResettableProbeMiddleware();
        MiddlewareCatalog::register(ResettableProbeMiddleware::class, static fn(): MiddlewareInterface => $probe);

        $pipeline = new MiddlewarePipeline(Context::getInstance());
        $this->handle($pipeline, '/first-request');
        $stack = $pipeline->debugStack();

        $pipeline->resetInstances();
        $this->handle($pipeline, '/second-request');

        $this->assertSame($stack, $pipeline->debugStack(), 'the boundary clear is not a rebuild');
        $this->assertSame('/second-request', $probe->sawPath);
    }

    /**
     * A middleware that cannot reset carries its state into the next request,
     * which is bad enough on its own -- taking the rest of the stack down with
     * it, so none of them reset either, would be worse.
     */
    public function testOneFailingResetDoesNotStopTheOthers(): void
    {
        $probe = new ResettableProbeMiddleware();
        MiddlewareCatalog::register(ThrowingResetMiddleware::class, static fn(): MiddlewareInterface => new ThrowingResetMiddleware());
        MiddlewareCatalog::register(ResettableProbeMiddleware::class, static fn(): MiddlewareInterface => $probe);

        $pipeline = new MiddlewarePipeline(Context::getInstance());
        $this->handle($pipeline, '/first-request');

        $pipeline->resetInstances();

        $this->assertSame(1, $probe->resets);
    }

    public function testResetInstancesOnAPipelineThatNeverBuiltIsANoOp(): void
    {
        $pipeline = new MiddlewarePipeline(Context::getInstance());

        $pipeline->resetInstances();

        $this->assertSame([], $pipeline->debugStack());
    }

    /**
     * The wiring, not just the method: the request boundary is where a worker's
     * reused middleware have to let go of the request they just served.
     */
    public function testTheRequestBoundaryResetsTheMiddlewareInstances(): void
    {
        $probe = new ResettableProbeMiddleware();
        MiddlewareCatalog::register(ResettableProbeMiddleware::class, static fn(): MiddlewareInterface => $probe);

        $context = Context::getInstance();
        $context->beginRequest();
        $handler = $context->getRequestHandler();
        $this->assertInstanceOf(\Quiote\Runtime\ContextRequestHandler::class, $handler);
        $pipeline = $handler->pipeline();
        $this->handle($pipeline, '/first-request');

        $context->reset();

        $this->assertSame(1, $probe->resets);
        $this->assertNull($probe->sawPath);
    }
}
