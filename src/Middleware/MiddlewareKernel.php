<?php
namespace Agavi\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Agavi\AgaviContext;
use Nyholm\Psr7\Response;
// import negotiation middleware (added)
use Agavi\Middleware\ContentNegotiationMiddleware;

/**
 * Builds and caches the PSR-15 middleware chain; safe for FrankenPHP worker reuse.
 */
class MiddlewareKernel implements RequestHandlerInterface
{
    private ?RequestHandlerInterface $handler = null;
    private bool $built = false;
    public function __construct(private AgaviContext $context) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    { if(!$this->built) { $this->build(); } return $this->handler->handle($request); }

    public function reset(): void { $this->handler = null; $this->built = false; }

    private function build(): void
    {
    // Terminal handler should never be reached if pipeline is well-formed; returns 500 otherwise.
    $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Response(500); } };
        $pipeline = new MiddlewarePipeline($final);
        $controller = $this->context->getController();
        $routing = $this->context->getRouting();
        // Proposed ordering mapped to phases
    $pipeline->add('TimingMiddleware', new TimingMiddleware(false), 'bootstrap', 100);
    $pipeline->add('TraceMiddleware', new TraceMiddleware(false), 'bootstrap', 90);
    $pipeline->add('JsonBodyParsingMiddleware', new JsonBodyParsingMiddleware(), 'bootstrap', 80);
    $pipeline->add('RoutingMiddleware', new RoutingMiddleware($routing, $controller), 'routing');
    // Content negotiation should run after routing (route may set _output_type) but before other before_action middlewares.
    // We keep it in the 'routing' phase with lower priority so it executes after RoutingMiddleware.
    $pipeline->add('ContentNegotiationMiddleware', new ContentNegotiationMiddleware($controller), 'routing', -5);
        $pipeline->add('FormPopulationMiddleware', new FormPopulationMiddleware(), 'before_action', 10);
        $pipeline->add('SecurityMiddleware', new SecurityMiddleware($controller), 'before_action', 0);
        $pipeline->add('ValidationMiddleware', new ValidationMiddleware(), 'before_action', 0);
        $pipeline->add('DispatchMiddleware', new DispatchMiddleware($controller), 'action');
        $pipeline->add('SlotMiddleware', new SlotMiddleware(), 'post', -5); // optional slot/post processing
        $pipeline->add('AssetAggregationMiddleware', new AssetAggregationMiddleware(), 'post');
        $pipeline->add('ExecutionTimeMiddleware', new ExecutionTimeMiddleware(), 'finalize', -10);
        $built = $pipeline->build();
        // Wrap with error handling as outermost layer
        $this->handler = new class(new ErrorHandlingMiddleware(), $built) implements RequestHandlerInterface {
            public function __construct(private ErrorHandlingMiddleware $err, private RequestHandlerInterface $next) {}
            public function handle(ServerRequestInterface $r): ResponseInterface { return $this->err->process($r, $this->next); }
        };
        $this->built = true;
    }
}
