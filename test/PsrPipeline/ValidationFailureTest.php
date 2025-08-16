<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\RoutingMiddleware;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\ValidationMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ActionDescriptor;

final class ValidationFailureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__,2);
    AgaviConfig::set('core.app_dir', $root . '/test/sandbox/app', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/src/Agavi.php';
        Agavi::bootstrap('test','web', ['prewarm'=>false]);
    }

    public function testValidationFailureInvokesHandleError(): void
    {
        $context = Agavi::context('web', true);
        $controller = $context->getController();
    $module = 'Cache';
    $action = 'CacheComplex'; // has handleError implementation
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Nyholm\Psr7\Response(500); } };
        $pipeline = new MiddlewarePipeline($final);
        $pipeline->add('SecurityMiddleware', new SecurityMiddleware($controller), 'before_action');
    $pipeline->add('ValidationMiddleware', new ValidationMiddleware($controller), 'before_action');
        $pipeline->add('DispatchMiddleware', new DispatchMiddleware($controller), 'action');
        $handler = $pipeline->build();
        $handler = new class(new ErrorHandlingMiddleware(), $handler) implements RequestHandlerInterface { public function __construct(private ErrorHandlingMiddleware $err, private RequestHandlerInterface $next) {} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->err->process($r, $this->next); } };
    $req = (new ServerRequest('GET', 'http://localhost/?fail=1'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);
        $resp = $handler->handle($req);
    // Validation failure should now return 400 Bad Request, not 200 OK.
    $this->assertSame(400, $resp->getStatusCode());
        $this->assertStringContainsString('Error', (string)$resp->getBody());
    }
}
