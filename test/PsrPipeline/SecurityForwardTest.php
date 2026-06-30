<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\RoutingMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\TimingMiddleware;
use Agavi\Middleware\TraceMiddleware;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\AssetAggregationMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ActionDescriptor;

final class SecurityForwardTest extends TestCase
{
    protected function setUp(): void
    {
        // Establish deterministic security-related config each run to eliminate ordering flakiness.
        AgaviConfig::set('core.use_security', true, true, true);
        AgaviConfig::set('actions.login_module', 'Default', true, true);
        AgaviConfig::set('actions.login_action', 'Login', true, true);
        AgaviConfig::set('actions.secure_module', 'Default', true, true);
        AgaviConfig::set('actions.secure_action', 'Secure', true, true);
        // Clear forced auth env that might be set by other tests.
        putenv('AGAVI_TEST_FORCE_AUTH=');
        // If a previous test authenticated the user, explicitly log them out so we exercise forward path reliably.
        try {
            $ctx = \Agavi\Agavi::context('web', true);
            $user = $ctx->getUser();
            if($user && method_exists($user, 'setAuthenticated')) { $user->setAuthenticated(false); }
        } catch(\Throwable) {}
    }
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__,2);
        AgaviConfig::set('core.app_dir', $root . '/app', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/src/Agavi.php';
        Agavi::bootstrap('test','web', ['prewarm'=>false]);
    }

    public function testSecureActionForwardProducesContent(): void
    {
        $context = Agavi::context('web', true);
        $controller = $context->getController();
        $module = 'ControllerTests';
        $action = 'Secure';
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Nyholm\Psr7\Response(500); } };
        $pipeline = new MiddlewarePipeline($final);
        $pipeline->add('TimingMiddleware', new TimingMiddleware(), 'bootstrap', 100);
        $pipeline->add('TraceMiddleware', new TraceMiddleware(), 'bootstrap', 90);
        $pipeline->add('SecurityMiddleware', new SecurityMiddleware($controller), 'before_action');
        $pipeline->add('DispatchMiddleware', new DispatchMiddleware($controller), 'action');
        $handler = $pipeline->build();
        $handler = new readonly class(new ErrorHandlingMiddleware(), $handler) implements RequestHandlerInterface { public function __construct(private ErrorHandlingMiddleware $err, private RequestHandlerInterface $next) {} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->err->process($r, $this->next); } };
        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);
        putenv('AGAVI_TEST_FORCE_AUTH='); // ensure not authenticated
        $resp = $handler->handle($req);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNotEmpty((string)$resp->getBody());
    }
}
