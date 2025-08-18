<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\TimingMiddleware;
use Agavi\Middleware\TraceMiddleware;
use Psr\Http\Server\MiddlewareInterface;
use Agavi\Middleware\RoutingMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\AssetAggregationMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ExecutionState;
use Agavi\Execution\ActionDescriptor;

final class PipelineOrderingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__,2);
    // Use sandbox app so validation XML and generated routes are present.
    AgaviConfig::set('core.app_dir', $root . '/test/sandbox/app', true, true);
        AgaviConfig::set('core.module_dir', $root . '/test/sandbox/app/Modules', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/src/Agavi.php';
        Agavi::bootstrap('test','web', ['prewarm'=>false]);
        $systemHandlers = $root . '/src/Config/defaults/config_handlers.xml';
        if(is_readable($systemHandlers)) { \Agavi\Config\AgaviConfigCache::addConfigHandlersFile($systemHandlers); }
        AgaviConfig::set('core.agavi_dir', $root . '/src', true, true);
    }

    public function testOrderingAndTrace(): void
    {
        $context = Agavi::context('web', true);
    $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Nyholm\Psr7\Response(200); } };
        $pipeline = new MiddlewarePipeline($final);
        $pipeline->add('TimingMiddleware', new TimingMiddleware(), 'bootstrap', 100);
    $pipeline->add('TraceMiddleware', new TraceMiddleware(true), 'bootstrap', 90);
        $pipeline->add('RoutingMiddleware', new RoutingMiddleware($context->getRouting(), $context->getController()), 'routing');
        $pipeline->add('SecurityMiddleware', new SecurityMiddleware($context->getController()), 'before_action');
    // Include validation to mirror real kernel ordering so non-simple actions pass dispatch precondition
    $pipeline->add('ValidationMiddleware', new \Agavi\Middleware\ValidationMiddleware($context->getController()), 'before_action', 0);
    $pipeline->add('DispatchMiddleware', new DispatchMiddleware($context->getController()), 'action');
        $pipeline->add('AssetAggregationMiddleware', new AssetAggregationMiddleware(), 'post');
        $pipeline->add('ExecutionTimeMiddleware', new ExecutionTimeMiddleware(), 'finalize');
        $handler = $pipeline->build();
        $handler = new class(new ErrorHandlingMiddleware(), $handler) implements RequestHandlerInterface { public function __construct(private ErrorHandlingMiddleware $err, private RequestHandlerInterface $next) {} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->err->process($r, $this->next); } };
    $req = new ServerRequest('GET', 'http://localhost/');
    // Attach empty execution state so middlewares can populate
    $req = $req->withAttribute(ExecutionState::class, new ExecutionState());
    $initialLevel = ob_get_level();
    $resp = $handler->handle($req);
    // Close only buffers opened within middleware (rare). If new levels added, trim back to initial.
    while(ob_get_level() > $initialLevel) { @ob_end_clean(); }
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertTrue($resp->hasHeader('X-Agavi-Trace'));
    }
}
