<?php
use PHPUnit\Framework\TestCase;
use Agavi\Agavi;
use Agavi\Config\AgaviConfig;
use Agavi\Middleware\SecurityMiddleware;
use Agavi\Middleware\DispatchMiddleware;
use Agavi\Middleware\ErrorHandlingMiddleware;
use Relay\Relay;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\ActionDescriptor;

/**
 * A non-simple action dispatched WITHOUT ValidationMiddleware in the stack must
 * fail closed (DispatchMiddleware returns 500) rather than run unvalidated.
 */
final class ValidationEnforcementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        AgaviConfig::set('core.app_dir', $root . '/test/sandbox/app', true, true);
        AgaviConfig::set('core.module_dir', $root . '/test/sandbox/app/Modules', true, true);
        $vendorAutoload = $root . '/vendor/autoload.php';
        if (is_readable($vendorAutoload)) { require_once $vendorAutoload; }
        require_once $root . '/src/Agavi.php';
        Agavi::bootstrap('testing', 'web', ['prewarm' => false]);
    }

    public function testNonSimpleActionWithoutValidationMiddlewareFails(): void
    {
        $context = Agavi::context('web', true);
        $controller = $context->getController();
        $module = 'Cache';
        $action = 'CacheComplex'; // non-simple action
        \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false, false, false);
        $desc = new ActionDescriptor($module, $action, 'GET', 'html', false);

        // Intentionally omit ValidationMiddleware. DispatchMiddleware is terminal.
        $stack = [
            new ErrorHandlingMiddleware(),
            new SecurityMiddleware($controller),
            new DispatchMiddleware($controller),
        ];

        $req = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute('module', $module)
            ->withAttribute('action', $action)
            ->withAttribute('output_type', 'html')
            ->withAttribute(ActionDescriptor::class, $desc);

        $resp = (new Relay($stack))->handle($req);
        $this->assertSame(500, $resp->getStatusCode(), 'Non-simple action without validation must fail with 500');
    }
}
