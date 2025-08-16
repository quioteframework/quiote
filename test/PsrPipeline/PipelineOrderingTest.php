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
        AgaviConfig::set('core.app_dir', $root . '/app', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/src/Agavi.php';
        Agavi::bootstrap('test','web', ['prewarm'=>false]);
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
        $pipeline->add('DispatchMiddleware', new DispatchMiddleware($context->getController()), 'action');
        $pipeline->add('AssetAggregationMiddleware', new AssetAggregationMiddleware(), 'post');
        $pipeline->add('ExecutionTimeMiddleware', new ExecutionTimeMiddleware(), 'finalize');
        $handler = $pipeline->build();
        $handler = new class(new ErrorHandlingMiddleware(), $handler) implements RequestHandlerInterface { public function __construct(private ErrorHandlingMiddleware $err, private RequestHandlerInterface $next) {} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->err->process($r, $this->next); } };
    $req = new ServerRequest('GET', 'http://localhost/');
    // Provide defaults so DispatchMiddleware has module/action even if test routing is minimal
    $module = AgaviConfig::get('actions.default_module');
    $action = AgaviConfig::get('actions.default_action');
    $req = $req->withAttribute('module', $module)
           ->withAttribute('action', $action)
           ->withAttribute('output_type', 'html')
           ->withAttribute(ActionDescriptor::class, new ActionDescriptor($module, $action, 'GET', 'html', true));
    $initialLevel = ob_get_level();
    $resp = $handler->handle($req);
    // Close only buffers opened within middleware (rare). If new levels added, trim back to initial.
    while(ob_get_level() > $initialLevel) { @ob_end_clean(); }
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertTrue($resp->hasHeader('X-Agavi-Trace'));
    }
}
