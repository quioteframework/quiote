<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Agavi\Execution\ActionDescriptor;

final class CredentialForwardTest extends TestCase
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

    public function testCredentialForward(): void
    {
        $context = Agavi::context('web', true);
        $controller = $context->getController();
        $module = 'ControllerTests';
        $action = 'CredentialsAction';
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);
        // Ensure user is authenticated but lacks required credential 'admin'
        $user = $context->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        if(method_exists($user,'clearCredentials')) { try { $user->clearCredentials(); } catch(\Throwable) {} }
        $final = new class implements RequestHandlerInterface { public function handle(ServerRequestInterface $r): ResponseInterface { return new Nyholm\Psr7\Response(500); } };
        $pipeline = new MiddlewarePipeline($final);
        $pipeline->add('SecurityMiddleware', new SecurityMiddleware($controller), 'before_action');
        $pipeline->add('DispatchMiddleware', new DispatchMiddleware($controller), 'action');
        $handler = $pipeline->build();
        $handler = new readonly class(new ErrorHandlingMiddleware(), $handler) implements RequestHandlerInterface { public function __construct(private ErrorHandlingMiddleware $err, private RequestHandlerInterface $next) {} public function handle(ServerRequestInterface $r): ResponseInterface { return $this->err->process($r, $this->next); } };
        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);
        $resp = $handler->handle($req);
        $this->assertSame(200, $resp->getStatusCode(), 'Credential forward should produce 200 content');
        $this->assertNotEmpty((string)$resp->getBody());
    }
}
