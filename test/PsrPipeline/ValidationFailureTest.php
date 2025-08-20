<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\ValidationMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Agavi\Middleware\OutputTypeSyncMiddleware;
use Agavi\Middleware\ContentNegotiationMiddleware;
use Agavi\Middleware\RoutingMiddleware;
use Relay\Relay;
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
        // Minimal stack replicating ordering: ErrorHandling -> ContentNegotiation -> Routing -> OutputTypeSync -> Security -> Validation -> Dispatch
        $stack = [
            new ErrorHandlingMiddleware(),
            new SecurityMiddleware($controller),
            new ValidationMiddleware($controller), // inject controller so action instantiation works reliably
            new DispatchMiddleware($controller),
        ];
        $handler = new class(new Relay($stack)) implements RequestHandlerInterface { public function __construct(private Relay $relay){} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->relay->handle($r); } };
    $req = (new ServerRequest('GET', 'http://localhost/?fail=1'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);
        $resp = $handler->handle($req);
    // Validation failure should return 400 Bad Request (handleError view executed by ValidationMiddleware)
    $this->assertSame(400, $resp->getStatusCode(), 'Expected validation failure to short-circuit with 400');
        $this->assertStringContainsString('Error', (string)$resp->getBody());
    }
}
